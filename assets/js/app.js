// ============================================================
// Sistema Imperio Comercial — app.js
// ============================================================

// ── Toast Notifications ───────────────────────────────────
window.showToast = function(msg, type = 'info', duration = 3500) {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `toast-msg ${type}`;
  const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
  toast.innerHTML = `<span>${icons[type] || ''} ${msg}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.transition = 'opacity .3s, transform .3s';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(20px)';
    setTimeout(() => toast.remove(), 300);
  }, duration);
};

// ── Sidebar Toggle (mobile overlay / desktop collapse) ────
function toggleSidebar() {
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  if (!sidebar) return;

  const isMobile = window.innerWidth <= 768;

  if (isMobile) {
    // Mobile: panel deslizante con backdrop
    sidebar.classList.toggle('open');
    if (backdrop) backdrop.classList.toggle('open');
  } else {
    // Desktop: colapsar / expandir (solo iconos)
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');

    // Cambiar ícono del botón
    const icon = document.querySelector('#sidebar-toggle i');
    if (icon) {
      icon.className = isCollapsed ? 'fa fa-bars-staggered' : 'fa fa-bars';
    }
  }
}

// Restaurar estado del sidebar al cargar
document.addEventListener('DOMContentLoaded', function() {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  const isMobile = window.innerWidth <= 768;
  if (!isMobile && localStorage.getItem('sidebarCollapsed') === '1') {
    sidebar.classList.add('collapsed');
    const icon = document.querySelector('#sidebar-toggle i');
    if (icon) icon.className = 'fa fa-bars-staggered';
  }
});

// Al cambiar tamaño de ventana: limpiar estados inconsistentes
window.addEventListener('resize', function() {
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  if (!sidebar) return;

  if (window.innerWidth > 768) {
    // Pasar a desktop: cerrar overlay mobile
    sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    // Restaurar estado de colapso desktop
    if (localStorage.getItem('sidebarCollapsed') === '1') {
      sidebar.classList.add('collapsed');
    }
  } else {
    // Pasar a mobile: quitar clase collapsed (usa transform en su lugar)
    sidebar.classList.remove('collapsed');
  }
});

// ── Confirm Delete ────────────────────────────────────────
document.addEventListener('click', function(e) {
  const btn = e.target.closest('[data-confirm]');
  if (!btn) return;
  const msg = btn.dataset.confirm || '¿Estás seguro?';
  if (!confirm(msg)) e.preventDefault();
});

// ── Auto-hide alerts ──────────────────────────────────────
document.querySelectorAll('.alert-ic').forEach(function(el) {
  setTimeout(() => {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  }, 4500);
});

// ── Format número como pesos en tiempo real ───────────────
window.formatPesos = function(val) {
  const n = parseFloat(val) || 0;
  return '$ ' + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// ── Calculador de cuotas (usado en creditos/nuevo.php) ────
window.calcularCuotas = function() {
  const precio  = parseFloat(document.getElementById('precio_articulo')?.value) || 0;
  const interes = parseFloat(document.getElementById('interes_pct')?.value) || 0;
  const cant    = parseInt(document.getElementById('cant_cuotas')?.value) || 1;

  if (precio <= 0 || cant <= 0) return;

  const total = precio * (1 + interes / 100);
  const cuota = total / cant;

  const elTotal  = document.getElementById('monto_total_display');
  const elCuota  = document.getElementById('monto_cuota_display');
  const inpTotal = document.getElementById('monto_total');
  const inpCuota = document.getElementById('monto_cuota');

  if (elTotal)  elTotal.textContent  = formatPesos(total);
  if (elCuota)  elCuota.textContent  = formatPesos(cuota);
  if (inpTotal) inpTotal.value = total.toFixed(2);
  if (inpCuota) inpCuota.value = cuota.toFixed(2);
};

// ── Mostrar/ocultar sección dia_cobro según frecuencia ───
window.toggleDiaCobro = function() {
  const frec  = document.getElementById('frecuencia');
  const grupo = document.getElementById('grupo_dia_cobro');
  if (!frec || !grupo) return;
  grupo.style.display = frec.value === 'semanal' ? '' : 'none';
};

// ── Modal genérico ────────────────────────────────────────
window.openModal = function(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
};
window.closeModal = function(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
};
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});
