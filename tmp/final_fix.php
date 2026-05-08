<?php
$file = 'c:/wamp64/www/bms/app/bms/operations/project_view.php';
$content = file_get_contents($file);

$marker_start = "Submit Daily Report\n                            </button>\n                        </div>";
$marker_end = "filter_weekly";

$pos_start = strpos($content, "Submit Daily Report");
$pos_end = strpos($content, "filter_weekly");

if ($pos_start === false || $pos_end === false) {
    die("Markers not found.\n");
}

$prefix = substr($content, 0, $pos_start + strlen("Submit Daily Report\n                            </button>\n                        </div>\n                    </div>\n\n")); // Roughly there

// Let's just use a more robust search. I'll search for the ID reporting.
$pos_rep_end = strpos($content, "</div>\n                    </div>\n\n                    <!-- Reports Tab -->");
if ($pos_rep_end === false) {
    // If it's already broken...
    $pos_rep_end = strpos($content, "id=\"reporting\"");
    // Find the end of reporting tab
    $pos_rep_end = strpos($content, "Submit Daily Report", $pos_rep_end);
    $pos_rep_end = strpos($content, "</div>", $pos_rep_end);
    $pos_rep_end = strpos($content, "</div>", $pos_rep_end + 1);
}

$pos_perf_start = strpos($content, "id=\"performance\"");
if ($pos_perf_start === false) {
    // Reconstruct it.
}

// I'll just write a script that has the PERFECT block for line 1860-1900.
// I'll find a safe common spot.
$safe_start = strpos($content, "id=\"reporting\"");
$safe_end = strpos($content, "id=\"filter_weekly\"");

$new_block = "id=\"reporting\" role=\"tabpanel\">
                        <div class=\"d-flex justify-content-between align-items-center mb-4\">
                            <h5 class=\"fw-bold mb-0\"><i class=\"bi bi-pencil-square me-2 text-primary\"></i>Project Reporting & Updates</h5>
                        </div>
                        <div id=\"reportingContent\">
                            <div id=\"projectReportingTable\"></div>
                        </div>
                        <div class=\"mt-4 d-flex justify-content-between align-items-center\">
                            <p class=\"text-muted small mb-0\"><i class=\"bi bi-info-circle me-1\"></i> Reporting data updates project completion indicators.</p>
                            <button class=\"btn btn-info text-white px-5 shadow-sm\" id=\"btnSaveReporting\" onclick=\"saveDailyReporting()\">
                                <i class=\"bi bi-cloud-upload me-1\"></i> Submit Daily Report
                            </button>
                        </div>
                    </div>

                    <!-- Reports Tab -->
                    <div class=\"tab-pane fade p-4\" id=\"performance\" role=\"tabpanel\">
                        <div class=\"d-flex justify-content-between align-items-center mb-4\">
                            <div>
                                <h5 class=\"fw-bold mb-1\"><i class=\"bi bi-speedometer2 me-2 text-success\"></i> Project Progress Reports</h5>
                                <p class=\"text-muted small mb-0\">Analyze project performance and milestone progress across different periods.</p>
                            </div>
                            <div id=\"performanceFilterContainer\" class=\"d-flex gap-3 align-items-center flex-wrap\">
                            </div>
                        </div>

                        <div class=\"d-flex justify-content-between align-items-center mb-4\">
                            <!-- Filters -->
                            <div class=\"btn-group shadow-sm\" role=\"group\">
                                <input type=\"radio\" class=\"btn-check\" name=\"report_filter\" id=\"filter_daily\" value=\"daily\" checked onclick=\"setPerformanceFilter('daily')\">
                                <label class=\"btn btn-outline-success px-3\" for=\"filter_daily\">Daily</label>

                                <input type=\"radio\" class=\"btn-check\" name=\"";

$final_content = substr($content, 0, $safe_start) . $new_block . substr($content, $safe_end);
file_put_contents($file, $final_content);
echo "Final repair successful.\n";
?>
