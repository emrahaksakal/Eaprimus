<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

$pdo = db();
$current_user_role = $_SESSION['role'] ?? 2;

if (!in_array($current_user_role, [1, 3])) {
    $_SESSION['mesaj'] = __("Hata") . ": " . __("Bu sayfayı görme yetkiniz yok.");
    header("Location: anasayfa");
    exit;
}

// Stats Queries
// 1. Total Open
$stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved', 'closed')");
$total_open = $stmt->fetchColumn() ?: 0;

// 2. SLA Breached Open (Overdue, 24h fallback)
$stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved', 'closed') AND (sla_due_date < NOW() OR (sla_due_date IS NULL AND create_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)))");
$sla_breached = $stmt->fetchColumn() ?: 0;

// 3. Avg Resolution Time limit last 100 resolved
$stmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, create_date, COALESCE(closed_date, resolved_date, update_date))) 
                     FROM (SELECT * FROM tickets WHERE status IN ('resolved','closed') ORDER BY id DESC LIMIT 100) sub");
$avg_res_minutes = (float) $stmt->fetchColumn();

// 4. Best queues in solving time (fastest 5)
$stmt = $pdo->query("SELECT c.name, AVG(TIMESTAMPDIFF(MINUTE, t.create_date, COALESCE(t.closed_date, t.resolved_date, t.update_date))) as avg_time 
                     FROM tickets t 
                     LEFT JOIN queues c ON t.queue_id = c.id
                     WHERE t.status IN ('resolved','closed') AND t.queue_id IS NOT NULL 
                     GROUP BY c.id 
                     ORDER BY avg_time ASC LIMIT 5");
$fastest_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Chart Data: Last 30 Days Trend
$chartLabels = [];
$chartCreated = [];
$chartResolved = [];

// Localized Date Helper
$mon_tr = [
    'Jan' => 'Oca', 'Feb' => 'Şub', 'Mar' => 'Mar', 'Apr' => 'Nis', 'May' => 'May', 'Jun' => 'Haz',
    'Jul' => 'Tem', 'Aug' => 'Ağu', 'Sep' => 'Eyl', 'Oct' => 'Eki', 'Nov' => 'Kas', 'Dec' => 'Ara'
];
$active_lang = $_SESSION['lang'] ?? 'tr';

for ($i = 29; $i >= 0; $i--) {
    $dateInfo = new DateTime("-$i days");
    $d = $dateInfo->format('Y-m-d');

    // Localized Date Label
    $day = $dateInfo->format('d');
    $mon = $dateInfo->format('M');
    $label = ($active_lang == 'tr') ? $day . ' ' . ($mon_tr[$mon] ?? $mon) : $day . ' ' . $mon;
    $chartLabels[] = $label;

    // Created
    $stmtO = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(create_date) = ?");
    $stmtO->execute([$d]);
    $chartCreated[] = $stmtO->fetchColumn();

    // Resolved
    $stmtR = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(COALESCE(closed_date, resolved_date, update_date)) = ? AND status IN ('resolved','closed')");
    $stmtR->execute([$d]);
    $chartResolved[] = $stmtR->fetchColumn();
}
?>

<div class="row">

    <div class="col-lg-3 col-6">
        <div class="small-box bg-info shadow-sm" style="border-radius:15px;">
            <div class="inner p-4">
                <h3 class="mb-1">
                    <?= $total_open ?>
                </h3>
                <p class="font-weight-bold mb-0"><?= __("active_tickets") ?></p>
            </div>
            <div class="icon" style="top:10px; right:20px;">
                <i class="fas fa-ticket-alt text-white opacity-50" style="font-size:3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-6">
        <div class="small-box bg-danger shadow-sm" style="border-radius:15px;">
            <div class="inner p-4">
                <h3 class="mb-1">
                    <?= $sla_breached ?>
                </h3>
                <p class="font-weight-bold mb-0"><?= __("sla_breach_open") ?></p>
            </div>
            <div class="icon" style="top:10px; right:20px;">
                <i class="fas fa-hourglass-end text-white opacity-50" style="font-size:3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-6">
        <div class="small-box bg-success shadow-sm" style="border-radius:15px;">
            <div class="inner p-4">
                <h3 class="mb-1">
                    <?php
                    if ($avg_res_minutes < 60) {
                        echo round($avg_res_minutes) . ' <sup style="font-size: 16px">' . __("min_short") . '</sup>';
                    } else {
                        echo round($avg_res_minutes / 60, 1) . ' <sup style="font-size: 16px">' . __("h_short") . '</sup>';
                    }
                    ?>
                </h3>
                <p class="font-weight-bold mb-0"><?= __("avg_resolution_time") ?></p>
            </div>
            <div class="icon" style="top:10px; right:20px;">
                <i class="fas fa-bolt text-white opacity-50" style="font-size:3rem;"></i>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-6">
        <div class="small-box bg-warning shadow-sm" style="border-radius:15px;">
            <div class="inner p-4">
                <h3 class="mb-1 text-white">
                    <?php
                    $bestVal = count($fastest_categories) ? $fastest_categories[0]['avg_time'] : 0;
                    if ($bestVal < 60) {
                        echo round($bestVal) . ' <sup style="font-size: 16px">' . __("min_short") . '</sup>';
                    } else {
                        echo round($bestVal / 60, 1) . ' <sup style="font-size: 16px">' . __("h_short") . '</sup>';
                    }
                    ?>
                </h3>
                <p class="font-weight-bold mb-0 text-white"><?= __("fastest_queue") ?>:
                    <?= count($fastest_categories) ? htmlspecialchars($fastest_categories[0]['name']) : '-' ?>
                </p>
            </div>
            <div class="icon" style="top:10px; right:20px;">
                <i class="fas fa-trophy text-white opacity-50" style="font-size:3rem;"></i>
            </div>
        </div>
    </div>

</div>

<div class="row">
    <!-- CHART -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0" style="border-radius:15px;">
            <div class="card-header bg-white border-0 py-3" style="border-radius:15px 15px 0 0;">
                <h3 class="card-title font-weight-bold"><i
                        class="fas fa-chart-area text-primary mr-2"></i><?= __("thirty_day_ticket_trend") ?></h3>
            </div>
            <div class="card-body">
                <canvas id="trendChart"
                    style="min-height: 250px; height: 300px; max-height: 300px; max-width: 100%;"></canvas>
            </div>
        </div>
    </div>

    <!-- FASTEST CATS -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0" style="border-radius:15px;">
            <div class="card-header bg-white border-0 py-3" style="border-radius:15px 15px 0 0;">
                <h3 class="card-title font-weight-bold"><i
                        class="fas fa-list text-primary mr-2"></i><?= __("fastest_solved_closed") ?>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-xs text-uppercase text-muted">
                            <tr>
                                <th class="px-4 py-3"><?= __("queue") ?></th>
                                <th class="text-right px-4 py-3"><?= __("avg_resolution_time") ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fastest_categories)): ?>
                                <tr>
                                    <td colspan="2" class="text-center py-5 text-muted"><?= __("no_solved_tickets_yet") ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fastest_categories as $index => $cat):
                                    $avgVal = $cat['avg_time'];
                                    $avgText = ($avgVal < 60) ? round($avgVal) . ' ' . __("min_short") : round($avgVal / 60, 1) . ' ' . __("h_short");
                                    ?>
                                    <tr onclick="window.location.href='biletler?queue=<?= $index ?>'" style="cursor:pointer;">
                                        <td class="px-4 py-3">
                                            <div class="d-flex align-items-center">
                                                <div class="mr-3 p-2 rounded bg-light text-primary">
                                                    <i class="fas fa-bolt"></i>
                                                </div>
                                                <span class="font-weight-600 text-dark"><?= htmlspecialchars($cat['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-right px-4 py-3">
                                            <span class="badge badge-light-primary border px-3 py-2" style="font-size:13px; font-weight:700;">
                                                <?= $avgText ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const ctx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: '<?= __("opened_tickets") ?>',
                        data: <?= json_encode($chartCreated) ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(220, 53, 69, 1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: '<?= __("solved_closed_tickets") ?>',
                        data: <?= json_encode($chartResolved) ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(40, 167, 69, 1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    });
</script>