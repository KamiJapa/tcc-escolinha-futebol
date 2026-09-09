<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

checkLogin();

$db = getDB();
$escolinha_id = $_SESSION['escolinha_id'] ?? 1;

// 1. Total Alunos Ativos
$stmt = $db->prepare("SELECT COUNT(*) FROM TB_ALUNO WHERE COD_ESCOLINHA = ? AND STATUS = 'ATIVO'");
$stmt->execute([$escolinha_id]);
$total_alunos = $stmt->fetchColumn();

// 2. Total Turmas Ativas
$stmt = $db->prepare("SELECT COUNT(*) FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1");
$stmt->execute([$escolinha_id]);
$total_turmas = $stmt->fetchColumn();

// 3. Frequência Média
$stmt = $db->prepare("
    SELECT AVG(PRESENTE) * 100 as media
    FROM TB_PRESENCA p
    JOIN TB_ALUNO a ON p.COD_ALUNO = a.COD_ALUNO
    WHERE a.COD_ESCOLINHA = ?
");
$stmt->execute([$escolinha_id]);
$presenca_media_raw = $stmt->fetchColumn();
$presenca_media = $presenca_media_raw ? round($presenca_media_raw, 1) : 0;

// 4. Turmas pie chart data (Alunos por turma)
$stmt = $db->prepare("
    SELECT t.NOME, COUNT(a.COD_ALUNO) as total
    FROM TB_TURMA t
    LEFT JOIN TB_ALUNO a ON t.COD_TURMA = a.COD_TURMA AND a.STATUS = 'ATIVO'
    WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1
    GROUP BY t.COD_TURMA
");
$stmt->execute([$escolinha_id]);
$turmas_data = $stmt->fetchAll();
$turmas_labels = json_encode(array_column($turmas_data, 'NOME'));
$turmas_counts = json_encode(array_column($turmas_data, 'total'));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar { background-color: #0f172a; } /* slate-900 */
        .sidebar-item:hover, .sidebar-item.active {
            background-color: rgba(16, 185, 129, 0.1); /* emerald-500 with opacity */
            color: #10b981; /* emerald-500 */
            border-left: 4px solid #10b981;
        }
        .sidebar-item {
            border-left: 4px solid transparent;
        }
    </style>
</head>
<body class="bg-slate-50 flex h-screen overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 sidebar text-slate-300 flex flex-col h-full shrink-0">
        <!-- Logo -->
        <div class="h-20 flex items-center px-6 border-b border-slate-800 bg-slate-900">
            <div class="w-10 h-10 bg-emerald-500 rounded-lg flex items-center justify-center mr-3 shrink-0 shadow-lg shadow-emerald-500/20">
                <i class="fas fa-futbol text-white text-xl"></i>
            </div>
            <div class="overflow-hidden">
                <h1 class="font-bold text-white leading-tight truncate">Escolinha Modelo G...</h1>
                <p class="text-[10px] text-emerald-500 font-bold tracking-wider">GESTORFC PRO</p>
            </div>
        </div>

        <!-- Menu -->
        <nav class="flex-1 overflow-y-auto py-6">
            <div class="px-6 mb-2">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Visão Geral</p>
            </div>
            <a href="/index.php" class="sidebar-item active flex items-center px-6 py-3 mb-4 text-emerald-500 bg-emerald-500/10 border-l-4 border-emerald-500">
                <i class="fas fa-chart-pie w-6"></i>
                <span class="font-medium text-sm">Dashboard Estratégico</span>
            </a>

            <div class="px-6 mb-2 mt-6">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Gestão Administrativa</p>
            </div>
            <a href="#" class="sidebar-item flex items-center px-6 py-3 text-slate-400 hover:text-emerald-500 transition-colors">
                <i class="fas fa-user-graduate w-6"></i>
                <span class="font-medium text-sm">Alunos & Matrículas</span>
            </a>
            <a href="#" class="sidebar-item flex items-center px-6 py-3 text-slate-400 hover:text-emerald-500 transition-colors">
                <i class="fas fa-users w-6"></i>
                <span class="font-medium text-sm">Turmas & Categorias</span>
            </a>
            <a href="#" class="sidebar-item flex items-center px-6 py-3 text-slate-400 hover:text-emerald-500 transition-colors">
                <i class="fas fa-user-friends w-6"></i>
                <span class="font-medium text-sm">Pais & Responsáveis</span>
            </a>

            <div class="px-6 mb-2 mt-6">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Módulo Esportivo</p>
            </div>
            <a href="#" class="sidebar-item flex items-center px-6 py-3 text-slate-400 hover:text-emerald-500 transition-colors">
                <i class="fas fa-clipboard-check w-6"></i>
                <span class="font-medium text-sm">Aulas & Chamada</span>
            </a>
            <a href="#" class="sidebar-item flex items-center px-6 py-3 text-slate-400 hover:text-emerald-500 transition-colors">
                <i class="fas fa-star w-6"></i>
                <span class="font-medium text-sm">Avaliações Técnicas</span>
            </a>
        </nav>

        <!-- User Profile -->
        <div class="p-4 border-t border-slate-800 bg-slate-900">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center text-white font-bold mr-3 border border-slate-700">
                    <?php echo substr($_SESSION['username'] ?? 'U', 0, 1); ?>
                </div>
                <div class="overflow-hidden">
                    <p class="text-sm font-bold text-white truncate"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuário'); ?></p>
                    <p class="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded uppercase inline-block font-bold mt-1"><?php echo htmlspecialchars($_SESSION['user_role'] ?? ''); ?></p>
                </div>
            </div>
            <a href="/logout.php" class="block w-full py-2 px-3 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm rounded transition-colors text-center border border-slate-700">
                <i class="fas fa-sign-out-alt mr-2 text-rose-500"></i> Encerrar Sessão
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden">
        
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 h-20 flex items-center px-8 shrink-0">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Dashboard Estratégico</h2>
                <p class="text-sm text-slate-500">Escolinha Modelo GestorFC (Demo) &bull; GestorFC</p>
            </div>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto p-8">
            
            <!-- Cards Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                
                <!-- Card 1 -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Alunos Matriculados</p>
                            <h3 class="text-3xl font-extrabold text-slate-800"><?php echo $total_alunos; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-graduate text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-emerald-500 font-medium"><i class="fas fa-check mr-1"></i> <?php echo $total_alunos; ?> no total geral</p>
                </div>

                <!-- Card 2 (Substituted Receita do Mês with Aulas) -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Turmas Ativas</p>
                            <h3 class="text-3xl font-extrabold text-emerald-500"><?php echo $total_turmas; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 font-medium">Turmas regulares cadastradas</p>
                </div>

                <!-- Card 3 (Placeholder for non-financial) -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Avisos Pendentes</p>
                            <h3 class="text-3xl font-extrabold text-rose-500">2</h3>
                        </div>
                        <div class="w-12 h-12 bg-rose-50 text-rose-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-rose-500 font-medium">Atenção requerida</p>
                </div>

                <!-- Card 4 -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm flex flex-col justify-between">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Presença Média</p>
                            <h3 class="text-3xl font-extrabold text-slate-800"><?php echo $presenca_media; ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-50 text-purple-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-emerald-500 font-medium"><?php echo $total_turmas; ?> turmas ativas</p>
                </div>

            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Chart 1 (Larger) -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm lg:col-span-2">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-slate-800">Evolução de Alunos (Simulação)</h3>
                            <p class="text-sm text-slate-500">Matrículas ativas vs Inativas</p>
                        </div>
                        <span class="bg-slate-100 text-slate-600 text-xs font-bold px-3 py-1 rounded-full">Mensal</span>
                    </div>
                    <div class="h-64 flex items-center justify-center bg-slate-50 rounded border border-dashed border-slate-300">
                         <div class="text-center">
                            <i class="fas fa-chart-bar text-slate-300 text-4xl mb-3"></i>
                            <p class="text-slate-400 text-sm">O Gráfico de Evolução Financeira foi desativado.<br>Será substituído por métricas esportivas.</p>
                         </div>
                    </div>
                </div>

                <!-- Chart 2 (Pie) -->
                <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-slate-800">Frequência por Categoria</h3>
                            <p class="text-sm text-slate-500">Taxa de presença nos treinos</p>
                        </div>
                        <span class="bg-emerald-50 text-emerald-600 text-xs font-bold px-3 py-1 rounded-full">Turmas</span>
                    </div>
                    <div class="relative h-64">
                        <canvas id="turmasChart"></canvas>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <script>
        // Pie Chart
        const ctx = document.getElementById('turmasChart').getContext('2d');
        const turmasChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $turmas_labels; ?>,
                datasets: [{
                    data: <?php echo $turmas_counts; ?>,
                    backgroundColor: [
                        '#10b981', // emerald-500
                        '#3b82f6', // blue-500
                        '#f59e0b', // amber-500
                        '#8b5cf6', // violet-500
                        '#ef4444'  // red-500
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
