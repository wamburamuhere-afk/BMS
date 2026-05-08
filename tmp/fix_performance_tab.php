<?php
$file = 'c:/wamp64/www/bms/app/bms/operations/project_view.php';
$content = file_get_contents($file);

$search = "Submit Daily Report\n                            </button>\n                        <div class=\"d-flex justify-content-between";
$replace = "Submit Daily Report\n                            </button>\n                        </div>\n                    </div>\n\n                    <!-- Reports Tab -->\n                    <div class=\"tab-pane fade p-4\" id=\"performance\" role=\"tabpanel\">\n                        <div class=\"d-flex justify-content-between align-items-center mb-4\">\n                            <div>\n                                <h5 class=\"fw-bold mb-1\"><i class=\"bi bi-speedometer2 me-2 text-success\"></i> Project Progress Reports</h5>\n                                <p class=\"text-muted small mb-0\">Analyze project performance and milestone progress across different periods.</p>\n                            </div>\n                            <div id=\"performanceFilterContainer\" class=\"d-flex gap-3 align-items-center flex-wrap\">\n                            </div>\n                        </div>\n                        <div class=\"d-flex justify-content-between align-items-center mb-4\">";

// Handle \r\n if present
$content_fixed = str_replace(str_replace("\n", "\r\n", $search), str_replace("\n", "\r\n", $replace), $content, $count);
if ($count === 0) {
    $content_fixed = str_replace($search, $replace, $content, $count);
}

if ($count > 0) {
    file_put_contents($file, $content_fixed);
    echo "Repaired Performance tab structure.\n";
} else {
    echo "Could not find the broken pattern.\n";
}
?>
