(() => {
  // El orden individual se conserva en localStorage. No modifica la configuración global.
  const key = 'csv-viewer-column-order';
  const table = document.querySelector('.data-table');
  if (!table) return;
  const saved = JSON.parse(localStorage.getItem(key) || '[]');
  const headers = [...table.querySelectorAll('thead th[data-column]')];
  const order = saved.filter(id => headers.some(h => h.dataset.column === id));
  headers.map(h => h.dataset.column).filter(id => !order.includes(id)).forEach(id => order.push(id));
  const reorder = () => table.querySelectorAll('tr').forEach(row => order.forEach(id => { const cell = row.querySelector(`[data-column="${id}"]`); if (cell) row.appendChild(cell); }));
  reorder();
  let dragged = null;
  table.querySelectorAll('thead th[data-column]').forEach(header => {
    header.draggable = true;
    header.addEventListener('dragstart', () => { dragged = header.dataset.column; });
    header.addEventListener('dragover', event => event.preventDefault());
    header.addEventListener('drop', event => { event.preventDefault(); const target = header.dataset.column; if (!dragged || dragged === target) return; order.splice(order.indexOf(dragged), 1); order.splice(order.indexOf(target), 0, dragged); localStorage.setItem(key, JSON.stringify(order)); reorder(); });
  });
})();
