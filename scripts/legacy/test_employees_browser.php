<!DOCTYPE html>
<html>
<head>
    <title>Test Employees API</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <h1>Testing Employees API</h1>
    <div id="status">Loading...</div>
    <pre id="response"></pre>
    
    <script>
    $(document).ready(function() {
        console.log('Testing API call...');
        
        // Test the exact URL that DataTables would use
        var testUrl = '<?php require "roots.php"; echo getUrl("api/get_employees"); ?>';
        
        $('#status').html('Testing URL: ' + testUrl);
        
        $.ajax({
            url: testUrl,
            type: 'GET',
            data: {
                draw: 1,
                start: 0,
                length: 10,
                search: { value: '' },
                order: [{ column: 0, dir: 'asc' }]
            },
            dataType: 'json',
            success: function(response) {
                console.log('Success!', response);
                $('#status').html('<span style="color:green">✓ API Call Successful!</span>');
                $('#response').text(JSON.stringify(response, null, 2));
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                console.log('Response:', xhr.responseText);
                $('#status').html('<span style="color:red">✗ API Call Failed: ' + error + '</span>');
                $('#response').text('Status: ' + xhr.status + '\n\nResponse:\n' + xhr.responseText);
            }
        });
    });
    </script>
</body>
</html>
