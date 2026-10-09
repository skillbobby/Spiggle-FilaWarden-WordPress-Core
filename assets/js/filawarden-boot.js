try {
  if (localStorage.getItem('filawarden-theme') === 'dark') {
    document.documentElement.classList.add('fw-dark');
  }
} catch (e) {}
