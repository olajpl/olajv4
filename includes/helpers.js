// public/js/helpers.js
function filterTable(inputId, tableId, rowClass) {
  const input = document.getElementById(inputId);
  const filter = input.value.toLowerCase();
  const rows = document.querySelectorAll(`#${tableId} .${rowClass}`);
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(filter) ? "" : "none";
  });
}
