(function () {
  var root = document.getElementById('fw-app');
  if (!root) return;
  var key = 'filawarden-theme';
  var btn = document.getElementById('fw-theme');
  function paint() {
    var dark = root.classList.contains('dark') || document.documentElement.classList.contains('fw-dark');
    root.classList.toggle('dark', dark);
    document.documentElement.classList.toggle('fw-dark', dark);
    if (btn) {
      btn.textContent = dark ? 'Light mode' : 'Dark mode';
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
  }
  if (localStorage.getItem(key) === 'dark') {
    root.classList.add('dark');
    document.documentElement.classList.add('fw-dark');
  }
  paint();
  if (btn) btn.addEventListener('click', function () {
    var dark = !root.classList.contains('dark');
    root.classList.toggle('dark', dark);
    document.documentElement.classList.toggle('fw-dark', dark);
    localStorage.setItem(key, dark ? 'dark' : 'light');
    paint();
  });
  var active = root.querySelector('.fw-nav a.active');
  if (active && window.matchMedia('(max-width: 760px)').matches) {
    active.scrollIntoView({ inline: 'center', block: 'nearest' });
  }
})();
