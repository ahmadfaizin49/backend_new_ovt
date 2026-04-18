<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OvertimeResource;
use App\Models\Overtime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OvertimeController extends Controller
{
    /**
     * GET /overtime
     * Ambil semua data lembur milik user login, urutkan terbaru.
     * Query params opsional:
     *   - bulan  (format: YYYY-MM) → filter per bulan
     *   - per_page (integer)       → pagination (default: semua)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Overtime::where('user_id', $request->user()->id)
            ->orderByDesc('tanggal')
            ->orderByDesc('created_at');

        // Filter per bulan
        if ($request->filled('bulan')) {
            $request->validate(['bulan' => 'date_format:Y-m']);
            [$tahun, $bulan] = explode('-', $request->bulan);
            $query->whereYear('tanggal', $tahun)->whereMonth('tanggal', $bulan);
        }

        // Pagination opsional
        if ($request->filled('per_page')) {
            $request->validate(['per_page' => 'integer|min:1|max:100']);
            $data    = $query->paginate((int) $request->per_page);
            $items   = OvertimeResource::collection($data->items());
            $total   = $data->items() ? array_sum(array_column(array_map(fn($o) => ['total' => $o->total], $data->items()), 'total')) : 0;

            return response()->json([
                'success' => true,
                'message' => 'Data lembur berhasil diambil',
                'data'    => $items,
                'meta'    => [
                    'current_page' => $data->currentPage(),
                    'last_page'    => $data->lastPage(),
                    'per_page'     => $data->perPage(),
                    'total_records' => $data->total(),
                    'total_lembur' => $total,
                ],
            ]);
        }

        $overtimes    = $query->get();
        $totalLembur  = $overtimes->sum('total');

        return response()->json([
            'success' => true,
            'message' => 'Data lembur berhasil diambil',
            'data'    => OvertimeResource::collection($overtimes),
            'meta'    => [
                'total_lembur' => $totalLembur,
            ],
        ]);
    }

    /**
     * POST /overtime
     * Buat data lembur baru.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal'    => 'required|date',
            'keterangan' => 'required|in:hari_biasa,libur',
            'jam_lembur' => 'required|integer|min:1',
        ]);

        $user        = $request->user();
        $tarifPerJam = Overtime::hitungTarifPerJam($user->gaji_pokok);
        $total       = Overtime::hitungTotal(
            $validated['jam_lembur'],
            $tarifPerJam,
            $validated['keterangan'],
            $user->work_days
        );

        $overtime = Overtime::create([
            'user_id'       => $user->id,
            'tanggal'       => $validated['tanggal'],
            'keterangan'    => $validated['keterangan'],
            'jam_lembur'    => $validated['jam_lembur'],
            'tarif_per_jam' => $tarifPerJam,
            'total'         => $total,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data lembur berhasil disimpan',
            'data'    => new OvertimeResource($overtime),
        ], 201);
    }

    /**
     * PUT /overtime/{id}
     * Update data lembur.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $overtime = Overtime::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'tanggal'    => 'required|date',
            'keterangan' => 'required|in:hari_biasa,libur',
            'jam_lembur' => 'required|integer|min:1',
        ]);

        $user        = $request->user();
        $tarifPerJam = Overtime::hitungTarifPerJam($user->gaji_pokok);
        $total       = Overtime::hitungTotal(
            $validated['jam_lembur'],
            $tarifPerJam,
            $validated['keterangan'],
            $user->work_days
        );

        $overtime->update([
            'tanggal'       => $validated['tanggal'],
            'keterangan'    => $validated['keterangan'],
            'jam_lembur'    => $validated['jam_lembur'],
            'tarif_per_jam' => $tarifPerJam,
            'total'         => $total,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data lembur berhasil diperbarui',
            'data'    => new OvertimeResource($overtime),
        ]);
    }

    /**
     * DELETE /overtime/{id}
     * Hapus data lembur.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $overtime = Overtime::where('user_id', $request->user()->id)->findOrFail($id);
        $overtime->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data lembur berhasil dihapus',
            'data'    => null,
        ]);
    }

    /**
     * GET /overtime/dashboard?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     * Dashboard ringkasan lembur: summary, weekly stats, history.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        $userId    = $request->user()->id;
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate   = Carbon::parse($request->end_date)->endOfDay();

        $overtimes = Overtime::where('user_id', $userId)
            ->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->orderByDesc('tanggal')
            ->get();

        // ── Summary ────────────────────────────────────────────
        $summary = [
            'total_jam'         => $overtimes->sum('jam_lembur'),
            'total_penghasilan' => $overtimes->sum('total'),
            'gaji_pokok'        => $request->user()->gaji_pokok,
        ];

        // ── Weekly Stats ────────────────────────────────────────
        $weeklyStats = [];
        $cursor      = $startDate->copy()->startOfWeek(Carbon::MONDAY);

        while ($cursor->lte($endDate)) {
            $weekStart = $cursor->copy();
            $weekEnd   = $cursor->copy()->endOfWeek(Carbon::SUNDAY);
            $sliceEnd  = $weekEnd->gt($endDate) ? $endDate->copy() : $weekEnd;

            $weekTotal = $overtimes
                ->filter(function ($o) use ($weekStart, $sliceEnd) {
                    $tgl = $o->tanggal->format('Y-m-d');
                    return $tgl >= $weekStart->toDateString() && $tgl <= $sliceEnd->toDateString();
                })
                ->sum('total');

            $weeklyStats[] = [
                'week'  => $weekStart->format('d M') . ' - ' . $sliceEnd->format('d M'),
                'total' => $weekTotal,
            ];

            $cursor->addWeek();
        }

        // ── History ─────────────────────────────────────────────
        $hariId = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulanId = [
            1 => 'Januari',
            2 => 'Februari',
            3  => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6  => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $historyData = $overtimes->map(function (Overtime $o) use ($hariId, $bulanId) {
            $tgl = $o->tanggal;
            return [
                'id'          => $o->id,
                'tanggal'     => $hariId[$tgl->dayOfWeek] . ', ' . $tgl->format('d') . ' ' . $bulanId[(int) $tgl->format('n')] . ' ' . $tgl->format('Y'),
                'tanggal_iso' => $tgl->format('Y-m-d'),
                'hari'        => $hariId[$tgl->dayOfWeek],
                'jam_lembur'  => $o->jam_lembur,
                'keterangan'  => $o->keterangan,
                'total'       => $o->total,
            ];
        });

        // Pagination opsional untuk history
        if ($request->filled('per_page')) {
            $perPage     = (int) $request->per_page;
            $currentPage = max(1, (int) ($request->page ?? 1));
            $paged       = $historyData->forPage($currentPage, $perPage);

            return response()->json([
                'success' => true,
                'message' => 'Dashboard overtime',
                'data'    => [
                    'summary'      => $summary,
                    'weekly_stats' => $weeklyStats,
                    'history'      => array_values($paged->toArray()),
                ],
                'meta' => [
                    'current_page'  => $currentPage,
                    'per_page'      => $perPage,
                    'total_records' => $historyData->count(),
                    'last_page'     => (int) ceil($historyData->count() / $perPage),
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Dashboard overtime',
            'data'    => [
                'summary'      => $summary,
                'weekly_stats' => $weeklyStats,
                'history'      => $historyData->values(),
            ],
        ]);
    }

    /**
     * GET /overtime/overview?mode=bulanan|mingguan
     *
     * Header card  : total jam & penghasilan bulan ini + gaji pokok
     * mode=bulanan : chart lembur per minggu dalam bulan ini (Minggu 1..5)
     * mode=mingguan: chart lembur per hari dalam minggu ini (Senin..Minggu)
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => 'nullable|in:bulanan,mingguan',
        ]);

        $mode = $request->input('mode', 'bulanan');
        $user = $request->user();

        $hariId  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulanId = [
            1 => 'Januari',
            2 => 'Februari',
            3  => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6  => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $now          = Carbon::now();
        $bulanMulai   = $now->copy()->startOfMonth();
        $bulanAkhir   = $now->copy()->endOfMonth();

        // ── Ambil semua data bulan ini (untuk header card) ──────
        $semuaBulanIni = Overtime::where('user_id', $user->id)
            ->whereBetween('tanggal', [$bulanMulai->toDateString(), $bulanAkhir->toDateString()])
            ->orderByDesc('tanggal')
            ->get();

        $totalJamBulan   = $semuaBulanIni->sum('jam_lembur');
        $totalLemburBulan = $semuaBulanIni->sum('total');

        // ── Header card ─────────────────────────────────────────
        $header = [
            'total_jam'       => $totalJamBulan,
            'gaji_pokok'      => $user->gaji_pokok,
            'total_with_gaji' => $user->gaji_pokok + $totalLemburBulan,
            'total_lembur'    => $totalLemburBulan,
        ];

        // ── Mode BULANAN: chart per minggu dalam bulan ini ───────
        if ($mode === 'bulanan') {
            $chart = [];
            $mingguKe = 1;
            $cursor   = $bulanMulai->copy();

            while ($cursor->lte($bulanAkhir)) {
                $start = $cursor->copy();
                $end   = $cursor->copy()->addDays(6);
                if ($end->gt($bulanAkhir)) {
                    $end = $bulanAkhir->copy();
                }

                $itemMinggu  = $semuaBulanIni
                    ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()]);

                $chart[] = [
                    'label' => 'Minggu ' . $mingguKe,
                    'jam'   => $itemMinggu->sum('jam_lembur'),
                    'total' => $itemMinggu->sum('total'),
                ];

                $cursor->addDays(7);
                $mingguKe++;
            }

            return response()->json([
                'success' => true,
                'message' => 'Overview overtime bulanan',
                'data'    => [
                    'header'  => $header,
                    'mode'    => 'bulanan',
                    'periode' => $bulanId[$now->month] . ' ' . $now->year,
                    'overview' => [
                        'total_jam'         => $totalJamBulan,
                        'total_penghasilan' => $totalLemburBulan,
                        'chart'             => $chart,
                    ],
                ],
            ]);
        }

        // ── Mode MINGGUAN: chart per hari dalam minggu ini ───────
        $mingguMulai = $now->copy()->startOfWeek(Carbon::MONDAY);
        $mingguAkhir = $now->copy()->endOfWeek(Carbon::SUNDAY);

        $dataMingguIni = Overtime::where('user_id', $user->id)
            ->whereBetween('tanggal', [$mingguMulai->toDateString(), $mingguAkhir->toDateString()])
            ->get()
            ->keyBy(fn($o) => $o->tanggal->toDateString());

        $chart = [];
        for ($i = 0; $i < 7; $i++) {
            $hari     = $mingguMulai->copy()->addDays($i);
            $key      = $hari->toDateString();
            $dataHari = $dataMingguIni[$key] ?? null;

            $chart[] = [
                'label' => $hariId[$hari->dayOfWeek],
                'jam'   => $dataHari ? $dataHari->jam_lembur : 0,
                'total' => $dataHari ? $dataHari->total : 0,
            ];
        }

        $totalJamMinggu    = $dataMingguIni->sum('jam_lembur');
        $totalLemburMinggu = $dataMingguIni->sum('total');

        return response()->json([
            'success' => true,
            'message' => 'Overview overtime mingguan',
            'data'    => [
                'header'  => $header,
                'mode'    => 'mingguan',
                'periode' => $mingguMulai->format('d') . ' - ' . $mingguAkhir->format('d ') . $bulanId[$mingguAkhir->month] . ' ' . $mingguAkhir->year,
                'overview' => [
                    'total_jam'         => $totalJamMinggu,
                    'total_penghasilan' => $totalLemburMinggu,
                    'chart'             => $chart,
                ],
            ],
        ]);
    }
}
