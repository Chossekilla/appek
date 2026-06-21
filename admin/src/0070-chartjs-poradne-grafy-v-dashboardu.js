// =============================================================
// 📊 CHART.JS — pořádné grafy v dashboardu
// =============================================================
window._chartInstances = window._chartInstances || {};

window.renderDashboardRevenueChart = function(data) {
  if (typeof Chart === 'undefined') {
    // Chart.js ještě nenačten — retry za 250ms
    return setTimeout(() => renderDashboardRevenueChart(data), 250);
  }
  const ctx = document.getElementById('dashboard-revenue-chart');
  if (!ctx) return;
  if (window._chartInstances.revenue) {
    try { window._chartInstances.revenue.destroy(); } catch (e) {}
  }

  const labels = data.map(r => {
    const d = new Date(r.den);
    return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'short' });
  });
  const trzby = data.map(r => +r.trzby);
  const objednavek = data.map(r => +r.objednavek);

  const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#BA7517';

  window._chartInstances.revenue = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Tržby (Kč)',
        data: trzby,
        borderColor: primary,
        backgroundColor: primary + '33',
        fill: true,
        tension: 0.35,
        borderWidth: 2.5,
        pointRadius: 3,
        pointHoverRadius: 6,
        yAxisID: 'y',
      }, {
        label: 'Objednávek',
        data: objednavek,
        borderColor: '#0a84ff',
        backgroundColor: '#0a84ff22',
        fill: false,
        tension: 0.35,
        borderWidth: 2,
        pointRadius: 2,
        pointHoverRadius: 5,
        yAxisID: 'y1',
        borderDash: [4, 4],
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 11.5, family: 'inherit' }, padding: 10, usePointStyle: true, pointStyle: 'circle' },
        },
        tooltip: {
          backgroundColor: 'rgba(28,28,30,0.95)',
          padding: 10,
          cornerRadius: 8,
          titleFont: { size: 12, family: 'inherit', weight: '600' },
          bodyFont: { size: 12, family: 'inherit' },
          callbacks: {
            label: (ctx) => {
              if (ctx.datasetIndex === 0) return ' Tržby: ' + new Intl.NumberFormat('cs-CZ', {style:'currency',currency:'CZK',maximumFractionDigits:0}).format(ctx.parsed.y);
              return ' Objednávek: ' + ctx.parsed.y;
            },
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true, position: 'left',
          ticks: { font: { size: 11, family: 'inherit' }, callback: (v) => new Intl.NumberFormat('cs-CZ', {maximumFractionDigits:0}).format(v) + ' Kč' },
          grid: { color: 'rgba(150,150,150,0.12)' },
        },
        y1: {
          beginAtZero: true, position: 'right',
          ticks: { font: { size: 11, family: 'inherit' }, precision: 0 },
          grid: { drawOnChartArea: false },
        },
        x: { ticks: { font: { size: 10.5, family: 'inherit' }, maxRotation: 0 }, grid: { display: false } },
      },
    },
  });
};

