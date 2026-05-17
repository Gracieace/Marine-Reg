/**
 * Utility to provide real-time searching within report tables
 * @param {string} searchInputId - The ID of the search input field
 * @param {string} tableId - The ID of the table to filter
 */
function initReportSearch(searchInputId, tableId) {
    const searchInput = document.getElementById(searchInputId);
    const table = document.getElementById(tableId);

    if (!searchInput || !table) return;

    searchInput.addEventListener('keyup', function() {
        const value = this.value.toLowerCase();
        const rows = table.getElementsByTagName('tr');

        // Start from 1 to skip header if necessary, or check for TD
        for (let i = 0; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            if (cells.length > 0) {
                let found = false;
                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].innerText.toLowerCase().indexOf(value) > -1) {
                        found = true;
                        break;
                    }
                }
                rows[i].style.display = found ? "" : "none";
            }
        }
    });
}
