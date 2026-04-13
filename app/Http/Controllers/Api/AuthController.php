<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $request->validate([
            'full_name' => 'required',
            'username' => 'required|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'gaji_pokok' => 'required|numeric',
            'work_days' => 'required|in:5hari,6hari',
            'password' => 'required|min:6'
        ], [
            'username.unique' => 'Username sudah digunakan',
            'email.unique' => 'Email sudah digunakan',
            'email.email' => 'Email tidak valid',
            'password.min' => 'Password minimal 6 karakter',
        ]);

        $user = User::create([
            'full_name' => $request->full_name,
            'username' => $request->username,
            'email' => $request->email,
            'gaji_pokok' => $request->gaji_pokok,
            'work_days' => $request->work_days,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Register berhasil',
            'user' => $user,
            'token' => $token
        ]);
    }

    // LOGIN
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required'
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Username atau password salah'
            ], 401);
        }

        $user->tokens()->delete(); // Hapus token lama jika ada
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'user' => $user,
            'token' => $token
        ]);
    }

    // LOGOUT
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6'
        ]);

        $user = $request->user();

        // cek password lama
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Password lama salah'
            ], 401);
        }

        // update password
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'message' => 'Password berhasil diubah'
        ]);
    }


    public function requestUpdateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required'
        ], [
            'email.unique' => 'Email sudah digunakan'
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password salah'
            ], 401);
        }

        $otp = rand(100000, 999999);

        DB::table('email_change_otps')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'new_email' => $request->email,
                'token' => Hash::make($otp),
                'created_at' => now()
            ]
        );

        try {
            Mail::raw("Kode OTP verifikasi email baru kamu: $otp (berlaku 5 menit)", function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Verifikasi Ganti Email');
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal kirim email',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'message' => 'OTP berhasil dikirim ke email baru'
        ]);
    }

    public function verifyUpdateEmail(Request $request)
    {
        $request->validate([
            'otp' => 'required'
        ]);

        $user = $request->user();

        $data = DB::table('email_change_otps')
            ->where('user_id', $user->id)
            ->first();

        if (!$data || !Hash::check($request->otp, $data->token)) {
            return response()->json([
                'message' => 'OTP tidak valid'
            ], 400);
        }

        if (now()->diffInMinutes($data->created_at) > 5) {
            return response()->json([
                'message' => 'OTP sudah kadaluarsa'
            ], 400);
        }

        $user->update(['email' => $data->new_email]);

        DB::table('email_change_otps')->where('user_id', $user->id)->delete();

        return response()->json([
            'message' => 'Email berhasil diubah',
            'email' => $data->new_email
        ]);
    }

    public function updateGaji(Request $request)
    {
        $request->validate([
            'gaji_pokok' => 'required|numeric|min:0',
            'password' => 'required'
        ]);

        $user = $request->user();

        // cek password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password salah'
            ], 401);
        }

        // update gaji
        $user->update([
            'gaji_pokok' => $request->gaji_pokok
        ]);

        return response()->json([
            'message' => 'Gaji pokok berhasil diupdate',
            'gaji_pokok' => $user->gaji_pokok
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'full_name' => 'nullable|string',
            'username' => 'nullable|unique:users,username,' . $request->user()->id,
            'work_days' => 'nullable|in:5hari,6hari',
        ], [
            'username.unique' => 'Username sudah digunakan',
        ]);

        $user = $request->user();

        $user->update([
            'full_name' => $request->full_name ?? $user->full_name,
            'username' => $request->username ?? $user->username,
            'work_days' => $request->work_days ?? $user->work_days,
        ]);

        return response()->json([
            'message' => 'Profile berhasil diupdate',
            'user' => $user
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $otp = rand(100000, 999999);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($otp),
                'created_at' => now()
            ]
        );

        try {
            Mail::raw("Kode OTP reset password kamu: $otp (berlaku 5 menit)", function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Reset Password');
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal kirim email',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'message' => 'OTP berhasil dikirim ke email'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required',
            'password' => 'required|min:6'
        ]);

        $data = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$data || !Hash::check($request->otp, $data->token)) {
            return response()->json([
                'message' => 'OTP tidak valid'
            ], 400);
        }

        if (now()->diffInMinutes($data->created_at) > 5) {
            return response()->json([
                'message' => 'OTP sudah kadaluarsa'
            ], 400);
        }

        User::where('email', $request->email)->update([
            'password' => Hash::make($request->password)
        ]);

        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Password berhasil direset'
        ]);
    }
}
