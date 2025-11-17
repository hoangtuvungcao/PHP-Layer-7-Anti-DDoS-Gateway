document.addEventListener('DOMContentLoaded', () => {
  const navLinks = document.querySelectorAll('header nav a[data-target]');
  const panels = document.querySelectorAll('.panel');
  const refreshButtons = document.querySelectorAll('[data-action="refresh"]');

  function activatePanel(target) {
    panels.forEach((panel) => {
      panel.classList.toggle('active', panel.id === target);
    });

    navLinks.forEach((link) => {
      link.classList.toggle('active', link.dataset.target === target);
    });
  }

  navLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      const target = link.dataset.target;
      if (target) {
        activatePanel(target);
      }
    });
  });

  refreshButtons.forEach((button) => {
    button.addEventListener('click', () => {
      window.location.reload();
    });
  });

  // Auto-refresh logs every 20 seconds if user is on logs panel.
  const AUTO_REFRESH_INTERVAL = 20000;
  setInterval(() => {
    const activePanel = document.querySelector('.panel.active');
    if (activePanel && activePanel.id === 'logs') {
      window.location.reload();
    }
  }, AUTO_REFRESH_INTERVAL);
});
