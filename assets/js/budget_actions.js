
// JavaScript Functions for Custom Buttons
function copyTable() {
    const table = document.getElementById('budgetTable');
    if (!table) return;
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try {
        document.execCommand('copy');
        alert('Table copied to clipboard!');
    } catch(err) {
        console.error('Unified copy failed', err);
    }
    window.getSelection().removeAllRanges();
}

function exportBudget() {
    let csv = [];
    const rows = document.querySelectorAll("#budgetTable tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length - 1; j++) // Skip action column
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        csv.push(row.join(","));
    }
    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const downloadLink = document.createElement("a");
    downloadLink.download = "Budget_Report_<?= $selected_year ?>_<?= $selected_month ?>.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function printBudget() {
    window.print();
}
