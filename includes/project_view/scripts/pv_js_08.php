function setPerformanceFilter(filter) {
    currentPerformanceFilter = filter;
    updatePerformanceFilterUI();
}

function updatePerformanceFilterUI() {
    const $container = $('#performanceFilterContainer');
    $container.empty();
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const todayStr = `${yyyy}-${mm}-${dd}`;

    let filterHtml = '';

    if (currentPerformanceFilter === 'daily') {
        filterHtml = `
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted">Date:</label>
                <input type="date" id="perf_daily_date" class="form-control form-control-sm" value="${todayStr}" onchange="loadPerformanceData()" style="width: 150px;">
            </div>`;
    } else if (currentPerformanceFilter === 'weekly') {
        const startDate = new Date();
        const endDate = new Date();
        endDate.setDate(startDate.getDate() + 6);
        
        const startStr = startDate.toISOString().split('T')[0];
        const endStr = endDate.toISOString().split('T')[0];

        filterHtml = `
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted">Range From:</label>
                <input type="date" id="perf_weekly_from" class="form-control form-control-sm" value="${startStr}" onchange="updateWeeklyRange()" style="width: 140px;">
                <label class="small fw-bold text-muted">To:</label>
                <input type="date" id="perf_weekly_to" class="form-control form-control-sm" value="${endStr}" readonly style="width: 140px; background-color: #f8fafc;">
            </div>`;
    } else if (currentPerformanceFilter === 'monthly') {
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        let options = '';
        months.forEach((m, i) => {
            options += `<option value="${i+1}" ${i+1 == mm ? 'selected' : ''}>${m}</option>`;
        });
        filterHtml = `
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted">Month:</label>
                <select id="perf_monthly_m" class="form-select form-select-sm" onchange="loadPerformanceData()" style="width: 120px;">${options}</select>
                <label class="small fw-bold text-muted">Year:</label>
                <input type="number" id="perf_monthly_y" class="form-control form-control-sm" value="${yyyy}" onchange="loadPerformanceData()" style="width: 90px;">
            </div>`;
    } else if (currentPerformanceFilter === 'quarterly') {
        const q = Math.ceil(mm / 3);
        filterHtml = `
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted">Quarter:</label>
                <select id="perf_quarterly_q" class="form-select form-select-sm" onchange="loadPerformanceData()" style="width: 130px;">
                    <option value="1" ${q==1?'selected':''}>Q1 (Jan-Mar)</option>
                    <option value="2" ${q==2?'selected':''}>Q2 (Apr-Jun)</option>
                    <option value="3" ${q==3?'selected':''}>Q3 (Jul-Sep)</option>
                    <option value="4" ${q==4?'selected':''}>Q4 (Oct-Dec)</option>
                </select>
                <label class="small fw-bold text-muted">Year:</label>
                <input type="number" id="perf_quarterly_y" class="form-control form-control-sm" value="${yyyy}" onchange="loadPerformanceData()" style="width: 90px;">
            </div>`;
    } else if (currentPerformanceFilter === 'annual') {
        filterHtml = `
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-bold text-muted">Year:</label>
                <input type="number" id="perf_annual_y" class="form-control form-control-sm" value="${yyyy}" onchange="loadPerformanceData()" style="width: 100px;">
            </div>`;
    }

    $container.html(filterHtml);
    loadPerformanceData();
}

function showWeekPicker() {
    Swal.fire({
        title: 'Select Any Day in the Week',
        input: 'date',
        inputAttributes: {
            max: new Date().toISOString().split('T')[0]
        },
        showCancelButton: true,
        confirmButtonText: 'Select Week',
        confirmButtonColor: '#198754'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            $('#perf_weekly_from').val(result.value);
            updateWeeklyRange();
        }
    });
}

function updateWeeklyRange() {
    const fromVal = $('#perf_weekly_from').val();
    if (!fromVal) return;
    
    // Safer way to parse date avoiding timezone shifts
    const parts = fromVal.split('-');
    const startDate = new Date(parts[0], parts[1]-1, parts[2]); // Using local midnight
    
    // Calculate End Date (Start Date + 6 days to make 7 days total)
    const endDate = new Date(startDate);
    endDate.setDate(startDate.getDate() + 6);
    
    // Format back to YYYY-MM-DD
    const startStr = startDate.getFullYear() + '-' + String(startDate.getMonth()+1).padStart(2, '0') + '-' + String(startDate.getDate()).padStart(2, '0');
    const endStr = endDate.getFullYear() + '-' + String(endDate.getMonth()+1).padStart(2, '0') + '-' + String(endDate.getDate()).padStart(2, '0');
    
    $('#perf_weekly_from').val(startStr);
    $('#perf_weekly_to').val(endStr);
    loadPerformanceData();
}

function loadPerformanceData() {
    let dateParam = '';
    let subtitle = '';
    const today = new Date();
    
    if (currentPerformanceFilter === 'daily') {
        dateParam = $('#perf_daily_date').val();
        subtitle = `DAILY PROJECT PROGRESS REPORT AS OF ${formatDate(dateParam)}`;
    } else if (currentPerformanceFilter === 'weekly') {
        dateParam = $('#perf_weekly_from').val();
        const toDate = $('#perf_weekly_to').val();
        subtitle = `WEEKLY PROJECT PROGRESS REPORT FROM ${formatDate(dateParam)} TO ${formatDate(toDate)}`;
    } else if (currentPerformanceFilter === 'monthly') {
        const m = parseInt($('#perf_monthly_m').val());
        const y = parseInt($('#perf_monthly_y').val());
        dateParam = `${y}-${String(m).padStart(2, '0')}-01`;
        const lastDay = new Date(y, m, 0).getDate();
        const endDate = `${y}-${String(m).padStart(2, '0')}-${lastDay}`;
        subtitle = `MONTHLY PROJECT PROGRESS REPORT FROM ${formatDate(dateParam)} TO ${formatDate(endDate)}`;
    } else if (currentPerformanceFilter === 'quarterly') {
        const q = parseInt($('#perf_quarterly_q').val());
        const y = parseInt($('#perf_quarterly_y').val());
        const startM = (q - 1) * 3 + 1;
        const endM = q * 3;
        dateParam = `${y}-${String(startM).padStart(2, '0')}-01`;
        const lastDay = new Date(y, endM, 0).getDate();
        const endDate = `${y}-${String(endM).padStart(2, '0')}-${lastDay}`;
        subtitle = `QUARTERLY PROJECT PROGRESS REPORT FROM ${formatDate(dateParam)} TO ${formatDate(endDate)}`;
    } else if (currentPerformanceFilter === 'annual') {
        const y = $('#perf_annual_y').val();
        dateParam = `${y}-01-01`;
        subtitle = `ANNUAL PROJECT PROGRESS REPORT FROM ${formatDate(`${y}-01-01`)} TO ${formatDate(`${y}-12-31`)}`;
    }
    
    $('#performanceReportSubtitle').text(subtitle);

    const $tbody = $('#performanceTable tbody');
    $tbody.html('<tr><td colspan="7" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2 text-success"></span> Loading report data...</td></tr>');

    $.getJSON(APP_URL + '/api/operations/get_milestones.php', { project_id: projectId }, function(mRes) {
        if (mRes.success) {
            $.getJSON(APP_URL + '/api/operations/get_progress_reports.php', {
                project_id: projectId,
                type: currentPerformanceFilter,
                date: dateParam
            }, function(pRes) {
                // INTEL: Always sync the global state with the latest overall progress from the server
                if (pRes.overall_progress !== undefined && projectData) {
                    if (projectData.data) projectData.data.progress_percent = pRes.overall_progress;
                    if (projectData.progress_analysis) {
                        projectData.progress_analysis.performance_total = pRes.overall_progress;
                        projectData.progress_analysis.cumulative_report_total = pRes.cumulative_total; // SYNC: Use this for consistent Row 2
                    }
                }
                renderPerformanceTable(mRes.data, pRes.data && pRes.data.length > 0 ? pRes.data[0] : null, pRes.cumulative_map);
                if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('performanceTable');
            });
        }
    });
}

function injectPrintSpacers() {
    $('.tab-pane.active table, .tab-pane.active .table').each(function() {
        if ($(this).find('.print-buffer-foot').length === 0) {
            $(this).append('<tfoot class="print-buffer-foot d-none d-print-table-footer-group"><tr><td colspan="100" style="height: 30mm !important;">&nbsp;</td></tr></tfoot>');
        }
    });
}

function printPerformanceReport() {
    // Capture the print details
    const now = new Date();
    const printDate = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    const printTime = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    
    $('#perfPrintUser').text(`${currentUserName} - ${currentUserRole}`);
    $('#perfPrintTimestamp').text(printDate + ' at ' + printTime);
    
    injectPrintSpacers();
    $('body').addClass('printing-report');
    window.print();
    $('body').removeClass('printing-report');
    $('.print-buffer-foot').remove();
}

async function exportPerformancePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt', 'a4');
    
    const title = $('#performanceReportTitle').text();
    const projectName = $('#projectNameReport').text();
    const subtitle = $('#performanceReportSubtitle').text();
    
    // Header colors
    const primaryBlue = [13, 110, 253];
    const pageW = doc.internal.pageSize.getWidth();

    // ── Company Logo — fetch full quality, let jsPDF scale it ──
    let logoImgData = null;
    const logoUrl = "<?= !empty($company_logo) ? getUrl($company_logo) : '' ?>";
    if (logoUrl) {
        try {
            logoImgData = await new Promise((resolve) => {
                fetch(logoUrl)
                    .then(r => r.blob())
                    .then(blob => {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const img = new Image();
                            img.onload = function() {
                                resolve({ data: e.target.result, w: img.naturalWidth, h: img.naturalHeight });
                            };
                            img.src = e.target.result;
                        };
                        reader.readAsDataURL(blob);
                    })
                    .catch(() => resolve(null));
            });
        } catch(e) { logoImgData = null; }
    }

    let headerY = 20; // Starting Y for logo / first text

    // 1. Draw logo centred at top
    if (logoImgData) {
        const maxW = 120, maxH = 60;
        const scale = Math.min(maxW / logoImgData.w, maxH / logoImgData.h, 1);
        const drawW = logoImgData.w * scale;
        const drawH = logoImgData.h * scale;
        const lx = (pageW - drawW) / 2;
        doc.addImage(logoImgData.data, lx, headerY, drawW, drawH);
        headerY += drawH + 12;
    }

    // 2. Company Name
    doc.setFontSize(18);
    doc.setTextColor(primaryBlue[0], primaryBlue[1], primaryBlue[2]);
    doc.setFont('helvetica', 'bold');
    doc.text("<?= strtoupper($company_name) ?>", pageW / 2, headerY + 10, { align: 'center' });
    headerY += 28;

    // 2.5 MAIN HEADING: PROJECT PROGRESS REPORT
    doc.setFontSize(16);
    doc.setTextColor(0, 0, 0); // Black
    doc.setFont('helvetica', 'bold');
    doc.text("PROJECT PROGRESS REPORT", pageW / 2, headerY, { align: 'center' });
    headerY += 22;

    // 3. Contract No
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.setFont('helvetica', 'normal');
    doc.text("CONTRACT NO: <?= strtoupper($contract_no) ?>", pageW / 2, headerY, { align: 'center' });
    headerY += 22;

    // 4. Project Name
    doc.setFontSize(14);
    doc.setTextColor(50, 50, 50);
    doc.setFont('helvetica', 'bold');
    doc.text(projectName, pageW / 2, headerY, { align: 'center' });
    headerY += 18;

    

    // 5. Report Title & Date Range (Subtitle)
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.setFont('helvetica', 'normal');
    doc.text(subtitle, pageW / 2, headerY, { align: 'center' });
    headerY += 10;

    // Draw line
    doc.setDrawColor(primaryBlue[0], primaryBlue[1], primaryBlue[2]);
    doc.setLineWidth(2);
    doc.line(pageW / 2 - 30, headerY + 8, pageW / 2 + 30, headerY + 8);

    const tableStartY = headerY + 30;
    
    const isDetailedActual = (currentPerformanceFilter !== 'daily');

    // Capture export info once (consistent across all pages)
    const exportNow = new Date();
    const exportDateStr = exportNow.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    const exportTimeStr = exportNow.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const exportedTime  = exportDateStr + ' at ' + exportTimeStr;
    const exportUser    = `${currentUserName} - ${currentUserRole}`;
    const pageWidth_    = doc.internal.pageSize.getWidth();
    const pageHeight_   = doc.internal.pageSize.getHeight();
    let   pageCount_    = 1;

    // Footer drawer – repeated on every page
    const drawPerfFooter = (pNum) => {
        doc.setFontSize(7.5);
        doc.setTextColor(150, 150, 150);
        doc.setDrawColor(220, 220, 220);
        doc.setLineWidth(0.5);
        doc.line(30, pageHeight_ - 28, pageWidth_ - 30, pageHeight_ - 28);
        doc.setFont('helvetica', 'normal');
        doc.text(
            `This report was Exported by ${exportUser} on ${exportedTime}`,
            pageWidth_ / 2, pageHeight_ - 18, { align: 'center' }
        );
        doc.setTextColor(13, 110, 253);
        doc.setFont('helvetica', 'bold');
        doc.text('Powered By BJP Technologies © 2026, All Rights Reserved', pageWidth_ / 2, pageHeight_ - 8, { align: 'center' });
    };

    // ── For Weekly/Monthly/Quarterly/Annual: temporarily switch DOM to print layout ──
    // This makes autoTable capture the same 3 "Actual" sub-columns that appear when printing.
    if (isDetailedActual) {
        // Hide the single screen-only "Actual" cells
        $('#performanceTable .d-print-none').addClass('_pdf_hidden').css('display', 'none');
        // Show the 3 print-only sub-columns (Previous / This Period / Cumulative)
        $('#performanceTable .d-print-table-cell').removeClass('d-none').css('display', 'table-cell');
        // Show the second header row that labels those sub-columns
        $('#performanceTable .d-print-table-row').removeClass('d-none').css('display', 'table-row');
    }

    doc.autoTable({
        html: '#performanceTable',
        startY: tableStartY,
        theme: 'grid', // White rows with clear grid lines like print
        headStyles: { 
            fillColor: [255, 255, 255], // White header background
            textColor: [33, 37, 41], 
            fontStyle: 'bold', // Headers back to bold as requested
            lineWidth: 0.5,
            lineColor: [222, 226, 230],
            halign: 'center',
            valign: 'middle',
            fontSize: 8 // Compact size to keep on one row
        },
        styles: { 
            fontSize: 8.5,
            cellPadding: 5,
            valign: 'middle',
            fillColor: [255, 255, 255] // Force white rows
        },
        columnStyles: {
            0: { cellWidth: 32, halign: 'center', overflow: 'hidden' }, // S/NO
            1: { cellWidth: 'auto', halign: 'left', overflow: 'linebreak' }, // Task Description (Can wrap)
            2: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // Unit
            3: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // Scope
            4: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // Previous / Actual
            5: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // This Period / Weight
            6: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // Cumulative / Progress
            7: { cellWidth: 45, halign: 'center', overflow: 'hidden' }, // Weight (Detailed)
            8: { cellWidth: 45, halign: 'center', overflow: 'hidden' }  // Progress % (Detailed)
        },
        didParseCell: function(data) {
            // Header Logic - STRICT: Only Quarters take 2 rows, others 1 row.
            if (data.section === 'head') {
                data.cell.styles.fontStyle = 'bold'; // Headers back to bold
                const headText = data.cell.text.join(' ').trim().toUpperCase();
                
                if (headText.startsWith('PREVIOUS QUARTER') || headText.startsWith('THIS QUARTER')) {
                    // Specifically split Quarters into 2 lines
                    let parts = headText.split(' ');
                    data.cell.text = [parts[0], parts.slice(1).join(' ')];
                    data.cell.styles.fontSize = 7;
                } else {
                    // Everything else on 1 row with small font
                    data.cell.styles.fontSize = 7.2;
                    data.cell.styles.minCellHeight = 0;
                }
            }

            // Footer Alignment Logic — borders & height apply to ALL cells in TOTAL/AGGREGATED rows
            if (data.section === 'foot') {
                const footText = data.cell.text.join(' ').trim().toUpperCase();
                const isFooterRow = footText.includes('TOTAL') || footText.includes('AGGREGATED');

                // Apply row-level styling to ALL cells in these footer rows
                // (checking row index so value cells that don't contain the keyword are also styled)
                const isTotalRow   = data.row.index === 0;
                const isAggrRow    = data.row.index === 1;

                if (isTotalRow || isAggrRow) {
                    // Full-row border
                    data.cell.styles.lineColor = [33, 37, 41];
                    data.cell.styles.lineWidth  = 0.6;
                    data.cell.styles.minCellHeight = 22;
                    data.cell.styles.cellPadding   = { top: 6, right: 5, bottom: 6, left: 5 };

                    // Label cell (wide colspan or long text) → right-align, faded bold
                    if (data.cell.colSpan > 1 || footText.length > 8) {
                        data.cell.styles.halign    = 'right';
                        data.cell.styles.fontSize  = 8.5;
                        data.cell.styles.textColor = [80, 80, 80];
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
            }

            // Apply colors matching print CSS intent
            if (data.section === 'body') {
                const tr = data.row.raw;
                const td = data.cell.raw;
                
                // ROBUST MAIN PHASE DETECTION
                let isMainPhase = false;
                if (tr) {
                    if (tr.getAttribute && tr.getAttribute('data-level') === '0') isMainPhase = true;
                    if (tr.classList && tr.classList.contains('bg-light-subtle')) isMainPhase = true;
                }
                if (td) {
                    if (td.classList && td.classList.contains('text-info')) isMainPhase = true;
                    const innerSpan = td.querySelector ? td.querySelector('span') : null;
                    if (innerSpan && (innerSpan.classList.contains('fw-bold') || (innerSpan.style && innerSpan.style.fontWeight >= 700))) {
                        isMainPhase = true;
                    }
                }
                
                const cellText = data.cell.text.join(' ');
                const actualIndex   = isDetailedActual ? 5 : 4;
                const progressIndex = isDetailedActual ? 8 : 6;

                // Color logic for specific columns (Actual/Progress) - Keep Teal if Main Phase
                if (data.column.index === actualIndex || data.column.index === progressIndex) {
                    if (cellText.includes('EXCEEDED')) {
                        data.cell.styles.textColor = [220, 53, 69];
                    } else if (isMainPhase) {
                        data.cell.styles.textColor = [13, 170, 185]; // Teal
                    } else {
                        data.cell.styles.textColor = [33, 37, 41];
                    }
                }
                
                // FINAL BOLDING: Task Description column (index 1) must be Bold-Black for Main Phases
                if (isMainPhase && data.column.index === 1) {
                    data.cell.styles.fontStyle = 'bold';
                    data.cell.styles.textColor = [0, 0, 0]; // BLACK
                }
            } else if (data.section === 'foot') {
                const progressIndex = isDetailedActual ? 8 : 6;
                const isAggrRow = (data.row.index === 1); // Aggregated Progress is Row 2

                if (data.column.index === progressIndex) {
                    if (isAggrRow) {
                        data.cell.styles.textColor = [13, 110, 253]; // Royal Blue (text-primary)
                        data.cell.styles.fontSize = 10; // Slightly larger for emphasis
                    } else {
                        data.cell.styles.textColor = [13, 170, 185]; // Teal
                    }
                }
            }
        },
        footStyles: {
            fillColor: [255, 255, 255],
            textColor: [33, 37, 41],
            fontStyle: 'bold',
            halign: 'center',
            fontSize: 9
        },
        margin: { bottom: 40 },
        showFoot: 'lastPage', // Ensure totals appear only at the end of the report
        didDrawPage: function() {
            drawPerfFooter(pageCount_);
            pageCount_++;
        }
    });

    // ── Restore DOM back to screen layout ──
    if (isDetailedActual) {
        $('#performanceTable ._pdf_hidden').removeClass('_pdf_hidden').css('display', '');
        $('#performanceTable .d-print-table-cell').addClass('d-none').css('display', '');
        $('#performanceTable .d-print-table-row').addClass('d-none').css('display', '');
    }
    
    doc.save(`Performance_Report_${subtitle.replace(/ /g, '_')}.pdf`);

    logReportAction('Exported Performance PDF', `User exported ${subtitle} for project ${projectName}`);
}


function renderPerformanceTable(milestones, report = null, cumulativeMap = null) {
    const $tbody = $('#performanceTable tbody');
    const $thead = $('#performanceTable thead');
    const $tfoot = $('#performanceTable tfoot');
    $tbody.empty();

    // Determine if we need the 3-column "Actual" layout (Weekly, Monthly, Yearly/Annual)
    const isDetailedActual = (currentPerformanceFilter !== 'daily');

    if (isDetailedActual) {
        let actualLabel = 'Actual Status';
        let prevLabel = 'Previous Period';
        let thisLabel = 'This Period';
        let cumLabel = 'Cumulative';

        if (currentPerformanceFilter === 'weekly') { 
            actualLabel = 'Weekly Implementation Status'; 
            prevLabel = 'Previous Week(s)'; 
            thisLabel = 'This Week'; 
            cumLabel = 'Cumulative';
        }
        else if (currentPerformanceFilter === 'monthly') { 
            actualLabel = 'Monthly Implementation Status'; 
            prevLabel = 'Previous Month(s)'; 
            thisLabel = 'This Month'; 
            cumLabel = 'Cumulative';
        }
        else if (currentPerformanceFilter === 'quarterly') { 
            actualLabel = 'Quarterly Implementation Status'; 
            prevLabel = 'Previous Quarter(s)'; 
            thisLabel = 'This Quarter'; 
            cumLabel = 'Cumulative';
        }
        else if (currentPerformanceFilter === 'annual') { 
            actualLabel = 'Annual Implementation Status'; 
            prevLabel = 'Previous Year(s)'; 
            thisLabel = 'This Year'; 
            cumLabel = 'Cumulative';
        }

        $thead.html(`
            <tr class="bg-light">
                <th rowspan="2" class="text-center align-middle" style="width: 50px;">S/NO</th>
                <th rowspan="2" class="text-center align-middle">Task Description / Phase</th>
                <th rowspan="2" class="text-center align-middle" style="width: 80px;">Unit</th>
                <th rowspan="2" class="text-center align-middle" style="width: 100px;">Scope</th>
                
                <!-- Display 'Actual' on screen -->
                <th rowspan="2" class="text-center align-middle d-print-none" style="width: 120px;">Actual</th>
                
                <!-- Display expanded Label on print -->
                <th colspan="3" class="text-center align-middle d-none d-print-table-cell">${actualLabel}</th>
                
                <th rowspan="2" class="text-center align-middle" style="width: 100px;">Weight (%)</th>
                <th rowspan="2" class="text-center align-middle" style="width: 100px;">Progress (%)</th>
            </tr>
            <tr class="bg-light d-none d-print-table-row">
                <th class="text-center small py-2 fw-bold" style="font-size: 0.7rem; border-top: 1px solid #dee2e6 !important;">${prevLabel}</th>
                <th class="text-center small py-2 fw-bold" style="font-size: 0.7rem; background-color: rgba(13, 110, 253, 0.05); border-top: 1px solid #dee2e6 !important;">${thisLabel}</th>
                <th class="text-center small py-2 fw-bold" style="font-size: 0.7rem; border-top: 1px solid #dee2e6 !important;">${cumLabel}</th>
            </tr>
        `);
        
        // Dynamic Footer colspans for print (Detailed view)
        $tfoot.find('#periodReportLabel').attr('colspan', '5').addClass('d-print-none');
        if ($tfoot.find('#periodReportLabelPrint').length === 0) {
            $tfoot.find('#periodReportLabel').after(`<td id="periodReportLabelPrint" colspan="7" class="d-none d-print-table-cell text-end ps-4 py-2 text-muted uppercase small">Total Report:</td>`);
        }
        
        $tfoot.find('tr.bg-white td:first-child').attr('colspan', '5').addClass('d-print-none');
        if ($tfoot.find('#aggregatedLabelPrint').length === 0) {
            $tfoot.find('tr.bg-white td:first-child').after(`<td id="aggregatedLabelPrint" colspan="7" class="d-none d-print-table-cell text-end ps-4 py-3" style="font-size: 1.1rem; letter-spacing: 0.5px; font-weight: bold;">AGGREGATED PROGRESS:</td>`);
        }
    } else {
        $thead.html(`
            <tr class="bg-light">
                <th class="text-center py-3" style="width: 60px;">S/NO</th>
                <th class="text-center py-3">Task Description / Phase</th>
                <th class="text-center py-3" style="width: 100px;">Unit</th>
                <th class="text-center py-3" style="width: 120px;">Scope</th>
                <th class="text-center py-3" style="width: 120px;">Actual</th>
                <th class="text-center py-3" style="width: 120px;">Weight (%)</th>
                <th class="text-center py-3" style="width: 120px;">Progress (%)</th>
            </tr>
        `);
        // Restore footer colspans for 7-column layout (Daily/Standard)
        $tfoot.find('#periodReportLabel').attr('colspan', '5').removeClass('d-print-none');
        $tfoot.find('#periodReportLabelPrint').remove();
        $tfoot.find('tr.bg-white td:first-child').attr('colspan', '5').removeClass('d-print-none');
        $tfoot.find('#aggregatedLabelPrint').remove();
    }

    // Update Report Metadata in Footer
    if (report && report.reported_by_name) {
        $('#perfReportedBy').text(report.reported_by_name);
        if (report.created_at && report.created_at.includes(' ')) {
            const parts = report.created_at.split(' ');
            const datePart = parts[0];
            const timePart = parts[1];
            $('#perfReportDate').text(formatDate(datePart));
            $('#perfReportTime').text(timePart);
        } else if (report.report_date) {
            $('#perfReportDate').text(formatDate(report.report_date));
            $('#perfReportTime').text('N/A');
        } else {
            $('#perfReportDate').text('N/A');
            $('#perfReportTime').text('N/A');
        }
    } else {
        $('#perfReportedBy').text('N/A');
        $('#perfReportDate').text('N/A');
        $('#perfReportTime').text('N/A');
    }

    // Update Report Comments and Attachments
    const hasComments = report && report.comments;
    const attachments = (report && report.attachments) ? report.attachments : [];
    if (hasComments || attachments.length > 0) {
        $('#perfReportComments').text(hasComments ? report.comments : '');
        $('#perfReportCommentsContainer').show();
        // Always render the left attachment column — show placeholder if empty
        const $list = $('#perfAttachmentList');
        $list.empty();
        if (attachments.length > 0) {
            attachments.forEach(function(att) {
                const label = att.attachment_name || att.file_path.split('/').pop();
                $list.append(`
                    <div class="border rounded p-2 bg-light d-flex align-items-start gap-2 w-100">
                        <i class="bi bi-file-earmark-text text-info fs-5 flex-shrink-0 mt-1"></i>
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="small fw-semibold mb-1" style="word-break:break-word; white-space:normal; line-height:1.4;">${label}</div>
                            <a href="${APP_URL}/${att.file_path}" target="_blank" class="btn btn-outline-info py-0 px-2" style="font-size:0.72rem;">
                                <i class="bi bi-box-arrow-up-right me-1"></i>Open
                            </a>
                        </div>
                    </div>`);
            });
        } else {
            $list.html('<p class="text-muted small fst-italic mb-0">No attachments for this report.</p>');
        }
    } else {
        $('#perfReportComments').text('');
        $('#perfReportCommentsContainer').hide();
    }

    if (milestones.length === 0) {
        $tbody.html(`<tr><td colspan="${isDetailedActual ? 9 : 7}" class="text-center py-4 text-muted">No milestones defined. Please set milestones first.</td></tr>`);
        return;
    }

    // Build hierarchy with actual values
    const milestoneMap = {};
    milestones.forEach(m => {
        let actual = 0;
        let progress = 0;
        let prevActual = 0;
        let fullCumActual = 0;
        
        if (report && report.details) {
            const detail = report.details.find(d => d.milestone_id == m.id);
            if (detail) {
                actual = parseFloat(detail.actual_value) || 0;
                progress = parseFloat(detail.progress_percent) || 0;
            }
        }
        
        // Map the previous data from API
        if (report && report.prev_details && report.prev_details[m.id]) {
            prevActual = parseFloat(report.prev_details[m.id]) || 0;
        }

        // INTEL: Use the Global All-Time Cumulative data if provided by the API
        if (cumulativeMap && cumulativeMap[m.id]) {
            fullCumActual = parseFloat(cumulativeMap[m.id]) || 0;
        } else {
            // Fallback for daily or if map missing
            fullCumActual = prevActual + actual;
        }
        
        milestoneMap[m.id] = { ...m, children: [], actual: actual, prevActual: prevActual, fullCumActual: fullCumActual, progress: progress };
    });

    const roots = [];
    milestones.forEach(m => {
        if (m.parent_id && milestoneMap[m.parent_id]) {
            milestoneMap[m.parent_id].children.push(milestoneMap[m.id]);
        } else {
            roots.push(milestoneMap[m.id]);
        }
    });

    function calculateRecursiveProgress(m, rootWeight) {
        if (m.children.length > 0) {
            let sumActual = 0;
            let sumPrevActual = 0;
            let sumFullCumActual = 0;
            let sumScope = 0;
            let sumProgress = 0;
            let sumCumProgress = 0;
            m.children.forEach(c => {
                const childResult = calculateRecursiveProgress(c, rootWeight);
                sumActual += childResult.actual;
                sumPrevActual += childResult.prevActual;
                sumFullCumActual += childResult.fullCumActual;
                sumScope += childResult.scope;
                sumProgress += childResult.progress;
                sumCumProgress += childResult.cumProgress;
            });
            m.actual = sumActual;
            m.prevActual = sumPrevActual;
            m.fullCumActual = sumFullCumActual;
            m.total_scope = sumScope;
            m.progress = m.children.length > 0 ? (sumProgress / m.children.length) : 0;
            m.cumProgress = m.children.length > 0 ? (sumCumProgress / m.children.length) : 0;
            return { progress: m.progress, cumProgress: m.cumProgress, actual: sumActual, prevActual: sumPrevActual, fullCumActual: sumFullCumActual, scope: sumScope };
        } else {
            const scope = parseFloat(m.scope) || 0;
            const p = (scope > 0) ? (m.actual / scope) * rootWeight : 0;
            // INTEL: Use all-time fullCumActual for global cumulative progress
            const cp = (scope > 0) ? (m.fullCumActual / scope) * rootWeight : 0;
            m.progress = p;
            m.cumProgress = cp;
            return { progress: p, cumProgress: cp, actual: m.actual, prevActual: m.prevActual, fullCumActual: m.fullCumActual, scope: scope };
        }
    }

    roots.forEach(r => {
        const rootWeight = parseFloat(r.weight_percent) || 0;
        calculateRecursiveProgress(r, rootWeight);
    });

    function renderRow(m, level = 0, parentId = '') {
        const hasChildren = m.children.length > 0;
        const scope = parseFloat(m.scope) || 0;
        const weight = parseFloat(m.weight_percent) || 0;
        const actual = m.actual;
        let progress = m.progress; 
        const rowId = `perf_row_${m.id}`;
        const rowScope = parseFloat(m.total_scope || m.scope) || 0;

        let baseColorClass = (level === 0) ? 'text-info' : 'text-dark';
        let colorClass = baseColorClass;
        let isExceeded = (actual > rowScope + 0.01 && rowScope > 0);
        if (isExceeded) colorClass = 'text-danger';

        let actualColsHtml = '';
        if (isDetailedActual) {
            const prevActual = m.prevActual || 0;
            const cumulative = prevActual + actual;
            
            actualColsHtml = `
                <!-- Screen: Actual -->
                <td class="text-center fw-bold ${colorClass} d-print-none" style="${level === 0 ? 'font-weight: 800; font-size: 1.05rem;' : ''}">${actual.toFixed(2)}</td>
                
                <!-- Print: Expanded -->
                <td class="text-center text-muted small d-none d-print-table-cell" style="opacity: 0.8;">${prevActual.toFixed(2)}</td>
                <td class="text-center fw-bold ${colorClass} d-none d-print-table-cell" style="${level === 0 ? 'font-weight: 800; font-size: 1.05rem;' : ''}; background-color: rgba(13, 110, 253, 0.05);">${actual.toFixed(2)}</td>
                <td class="text-center fw-bold text-dark d-none d-print-table-cell" style="${level === 0 ? 'font-weight: 800;' : ''}">${m.fullCumActual.toFixed(2)}</td>
            `;
        } else {
            actualColsHtml = `<td class="text-center fw-bold ${colorClass}" style="${level === 0 ? 'font-weight: 800; font-size: 1.05rem;' : ''}">${actual.toFixed(2)}</td>`;
        }

        const row = `
            <tr class="perf-row ${level === 0 ? 'bg-light-subtle' : ''}" id="${rowId}" 
                data-parent="${parentId}" data-level="${level}" data-weight="${weight}" 
                data-calc-progress="${m.progress}" data-cum-progress="${m.cumProgress}">
                <td class="text-center text-muted small p-id-cell">-</td>
                <td style="padding-left: ${level * 30 + 15}px !important;">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm p-0 border-0 me-1 toggle-perf-subtasks d-print-none" 
                                onclick="togglePerfSubtasks('${rowId}')" 
                                style="visibility: ${hasChildren ? 'visible' : 'hidden'}; width: 20px; outline: none !important; box-shadow: none !important;">
                            <i class="bi bi-caret-down-fill text-muted"></i>
                        </button>
                        <span class="${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800; font-size: 1.05rem;' : ''}">${m.description}</span>
                    </div>
                </td>
                <td class="text-center"><span class="badge bg-light text-dark ${level === 0 ? 'fw-bold' : ''}" style="${level === 0 ? 'font-weight: 800;' : ''}">${m.unit}</span></td>
                <td class="text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800;' : ''}">${scope.toFixed(2)}</td>
                ${actualColsHtml}
                <td class="text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800;' : ''}">${weight.toFixed(2)}<small class="d-print-none">%</small></td>
                <td class="text-center fw-bold ${colorClass}" style="${level === 0 ? 'font-weight: 800; font-size: 1.1rem;' : ''}">
                    ${m.cumProgress.toFixed(2)}<small class="d-print-none">%</small>
                    ${isExceeded ? '<br><small class="fw-bold">EXCEEDED!</small>' : ''}
                </td>
            </tr>
        `;
        $tbody.append(row);
        m.children.forEach(c => renderRow(c, level + 1, rowId));
    }

    roots.forEach(r => renderRow(r));
    reindexPerformance();
    updatePerformanceTotals();
}

function togglePerfSubtasks(rowId) {
    const $icon = $(`#${rowId}`).find('.toggle-perf-subtasks i');
    const isCollapsed = $icon.hasClass('bi-caret-right-fill');

    if (isCollapsed) {
        $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
        recursiveTogglePerf(rowId, false);
    } else {
        $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
        recursiveTogglePerf(rowId, true);
    }
    reindexPerformance();
}

function recursiveTogglePerf(parentId, hide) {
    $(`.perf-row[data-parent="${parentId}"]`).each(function() {
        const childId = $(this).attr('id');
        if (hide) {
            $(this).hide();
            recursiveTogglePerf(childId, true);
        } else {
            $(this).show();
            const $childIcon = $(this).find('.toggle-perf-subtasks i');
            if (!$childIcon.hasClass('bi-caret-right-fill')) {
                recursiveTogglePerf(childId, false);
            }
        }
    });
}

function reindexPerformance() {
    let count = 0;
    $('#performanceTable tbody tr').each(function() {
        if ($(this).css('display') !== 'none' && $(this).hasClass('perf-row')) {
            count++;
            $(this).find('.p-id-cell').text(count);
        }
    });
}

function updatePerformanceTotals() {
    let currentPeriodSum = 0;
    let totalWeightSum = 0;
    
    $('.perf-row[data-level="0"]').each(function() {
        let weight = parseFloat($(this).attr('data-weight')) || 0;
        let prog = parseFloat($(this).attr('data-calc-progress')) || 0;
        
        totalWeightSum += weight;
        currentPeriodSum += prog;
    });
    
    // Cap Weight at 100% as requested
    const finalWeightTotal = Math.min(100, totalWeightSum);
    
    // Label for Row 1 based on active report period
    const filterLabels = {
        'daily': 'TOTAL DAILY REPORT:',
        'weekly': 'TOTAL WEEKLY REPORT:',
        'monthly': 'TOTAL MONTHLY REPORT:',
        'quarterly': 'TOTAL QUARTERLY REPORT:',
        'annual': 'TOTAL ANNUAL REPORT:'
    };
    $('#periodReportLabel').text(filterLabels[currentPerformanceFilter] || 'TOTAL REPORT:');
    
    // Populate Row 1: The as-is sum of level-0 roots in the current table (Period Specific)
    $('#periodWeightTotal').html(finalWeightTotal.toFixed(2) + '<small class="d-print-none">%</small>');
    $('#periodProgressTotal').html(currentPeriodSum.toFixed(2) + '<small class="d-print-none">%</small>');
    
    // Row 1 update (The specific period total: Daily, Weekly, etc.)
    $('#periodProgressTotal').html(currentPeriodSum.toFixed(2) + '<small class="d-print-none">%</small>');
    
    // Determine Global Aggregated Progress (Overall Cumulative Progress)
    // INTEL: Aggregated Progress must reflect the TRUE OVERALL state of the project (All-time)
    let overallProgress = 0;
    
    // SYNC: We now always prioritize the global total calculated and synced from the latest API response
    if (projectData && projectData.progress_analysis && projectData.progress_analysis.cumulative_report_total !== undefined) {
        overallProgress = parseFloat(projectData.progress_analysis.cumulative_report_total);
    } else if (projectData && projectData.progress_analysis && projectData.progress_analysis.performance_total !== null) {
        overallProgress = parseFloat(projectData.progress_analysis.performance_total);
    } else if (projectData && projectData.data && projectData.data.progress_percent !== null) {
        overallProgress = parseFloat(projectData.data.progress_percent);
    } else {
        // Final fallback: sum up from rows (will be period-based)
        let sumFromRows = 0;
        $('.perf-row[data-level="0"]').each(function() {
            sumFromRows += parseFloat($(this).attr('data-cum-progress')) || 0;
        });
        overallProgress = sumFromRows;
    }

    const finalDisplayVal = overallProgress.toFixed(2);
    
    // Update the Row 2 display (Aggregated Milestone Totals)
    $('#globalAggregatedProgress').html(finalDisplayVal + '<small class="d-print-none">%</small>');

    // SYNC GLOBAL OVERALL PROGRESS (Dashboard Summary, Big Header Number & Metrics Breakdown)
    $('#progressTextDisplay').text(finalDisplayVal + '%');
    $('#performanceProgressBreakdownVal').text(finalDisplayVal + '%');
    $('#timelineProgressBreakdownVal').text(finalDisplayVal + '%');
    $('#progressBarDisplay')
        .css('width', overallProgress + '%')
        .removeClass('bg-success bg-warning bg-danger bg-info')
        .addClass(getProgressColor(overallProgress));

    if (projectData && projectData.data) {
        const cs = parseFloat(projectData.data.form_contract_sum) || 0;
        const execVal = (overallProgress / 100) * cs;
        $('#executedDisplay').text(formatMoney(execVal) + ' TZS');
        const fin2 = projectData.financial_summary || {};
        const unBilled2 = execVal - (parseFloat(fin2.total_revenue) || 0);
        $('#revenueUnbilledDisplay').text(formatMoney(unBilled2) + ' TZS');
    }
}

