// Filter dropdowns (includes/FilterBar.php): tick several options, and the filter
// applies when the dropdown closes (click outside, Esc, or another dropdown) — Apply is optional.
document.querySelectorAll('form[data-filter-bar]').forEach(function(form) {
  const dropdowns = form.querySelectorAll('.filter-dd');
  let dirty = false;

  form.addEventListener('change', function(e) {
    if (e.target.matches('input[type=checkbox]')) dirty = true;
  });

  function closeAll(except) {
    dropdowns.forEach(function(d) { if (d !== except) d.open = false; });
  }

  dropdowns.forEach(function(dd) {
    dd.addEventListener('toggle', function() {
      if (dd.open) {
        closeAll(dd);
        const find = dd.querySelector('.dd-find');
        if (find) find.focus();
      } else if (dirty) {
        dirty = false;
        form.submit();
      }
    });
    const find = dd.querySelector('.dd-find');
    if (find) {
      find.addEventListener('input', function() {
        const term = find.value.trim().toLowerCase();
        dd.querySelectorAll('.dd-option').forEach(function(opt) {
          opt.hidden = term !== '' && !opt.textContent.toLowerCase().includes(term);
        });
      });
      // Enter in the find box shouldn't submit with a half-typed term.
      find.addEventListener('keydown', function(e) { if (e.key === 'Enter') e.preventDefault(); });
    }
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('.filter-dd')) closeAll(null);
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAll(null);
  });
  // The × inside a dropdown's summary is a link; don't let the click also toggle it open.
  form.querySelectorAll('.dd-clear').forEach(function(a) {
    a.addEventListener('click', function(e) { e.stopPropagation(); dirty = false; });
  });
});
