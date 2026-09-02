// ============================================================
// Sistema Imperio Comercial — app.js
// ============================================================

// ── Toast Notifications ───────────────────────────────────
window.showToast = function (msg, type = 'info', duration = null) {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `toast-msg ${type}`;
  toast.setAttribute('role', 'alert');
  toast.setAttribute('aria-live', 'polite');
  const icons = { success: '✓', error: '✗', warning: '!', info: 'i' };
  toast.innerHTML = `<span>${icons[type] || ''} ${msg}</span>`;
  container.appendChild(toast);
  // Los toasts de error se muestran centrados arriba (ver .toast-msg.error en
  // style.css) para que no pasen inadvertidos como un toast normal de esquina;
  // por eso necesitan su propia animación de salida y quedan más tiempo.
  const isError = type === 'error';
  const hideTransform = isError ? 'translate(-50%, -16px)' : 'translateY(20px)';
  setTimeout(() => {
    toast.style.transition = 'opacity .3s, transform .3s';
    toast.style.opacity = '0';
    toast.style.transform = hideTransform;
    setTimeout(() => toast.remove(), 300);
  }, duration ?? (isError ? 6000 : 3500));
};

// ── Compartir Cupón de Pago (Web Share API) ───────────────
// Comparte el cupon como ARCHIVO (no como link) usando el share nativo del
// celular — el link a cupon_ver.php exige sesion iniciada, asi que no serviria
// si se lo mandaramos directo al cliente; compartiendo el archivo ya descargado
// se evita ese problema por completo. Usado desde cobrador/agenda.php (al
// registrar un pago) y cobrador/cupones.php (pantalla de consultas).
window.compartirCuponWhatsApp = async function (cuponUrl) {
  // El Web Share API con archivos exige contexto seguro (HTTPS, o localhost) —
  // sobre http:// plano (ej. accediendo por IP de red local) navigator.share
  // directamente no existe. console.warn deja rastro para diagnosticar a
  // distancia (F12 -> Console del celular, o remote debugging por USB).
  if (!window.isSecureContext) {
    console.warn('compartirCuponWhatsApp: no es contexto seguro (isSecureContext=false). Hace falta HTTPS (o localhost) para que exista navigator.share.');
    showToast('Para compartir por WhatsApp hace falta acceder al sistema por HTTPS. Abrí "Ver cupón" y compartilo desde el visor de PDF.', 'error');
    return;
  }
  if (!navigator.share) {
    console.warn('compartirCuponWhatsApp: navigator.share no existe en este navegador.');
    showToast('Tu navegador no tiene la función nativa de compartir. Abrí "Ver cupón" y compartilo desde el visor de PDF.', 'error');
    return;
  }
  try {
    const resp = await fetch(cuponUrl, { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('No se pudo descargar el cupón (' + resp.status + ')');
    const blob = await resp.blob();
    const file = new File([blob], 'cupon_pago.pdf', { type: 'application/pdf' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title: 'Cupón de pago' });
    } else {
      console.warn('compartirCuponWhatsApp: navigator.share existe pero no soporta compartir archivos (canShare files = false).');
      showToast('Tu navegador no permite compartir el archivo directo. Abrí "Ver cupón" y usá el botón compartir del visor de PDF.', 'error');
    }
  } catch (err) {
    if (err.name !== 'AbortError') {
      console.warn('compartirCuponWhatsApp: error inesperado —', err);
      showToast('No se pudo compartir el cupón: ' + err.message, 'error');
    }
  }
};

// ── Sidebar Toggle (mobile overlay / desktop collapse) ────
function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
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
document.addEventListener('DOMContentLoaded', function () {
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
window.addEventListener('resize', function () {
  const sidebar = document.getElementById('sidebar');
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
document.addEventListener('click', function (e) {
  const btn = e.target.closest('[data-confirm]');
  if (!btn) return;
  const msg = btn.dataset.confirm || '¿Estás seguro?';
  if (!confirm(msg)) e.preventDefault();
});

// ── Auto-hide alerts ──────────────────────────────────────
document.querySelectorAll('.alert-ic').forEach(function (el) {
  setTimeout(() => {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  }, 4500);
});

// ── Format número como pesos en tiempo real ───────────────
window.formatPesos = function (val) {
  const n = parseFloat(val) || 0;
  return '$ ' + n.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
};

// ── Modal genérico ────────────────────────────────────────
window.openModal = function (id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('open');
};
window.closeModal = function (id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
};
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

// ── Prevenir doble submit en formularios críticos ─────────
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form.form-ic:not([data-no-disable])').forEach(function (form) {
    form.addEventListener('submit', function () {
      const btn = form.querySelector('[type="submit"]:not([data-no-disable])');
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      btn.dataset.originalText = btn.innerHTML;
      btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Procesando…';
    });
  });
});
