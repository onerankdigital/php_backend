<?php
/**
 * Fix File Links - Web Version
 * Update existing file_link URLs to include port 8080
 */

require_once __DIR__ . '/config.php';

// Simple authentication check (you can enhance this)
$action = $_GET['action'] ?? 'view';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix File Links</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1000px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 40px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .status-box {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .status-box.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .status-box.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .status-box.warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .status-box.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .old-link {
            color: #dc3545;
            word-break: break-all;
        }
        .new-link {
            color: #28a745;
            word-break: break-all;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            margin-right: 10px;
        }
        button:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        button.danger {
            background: #dc3545;
        }
        button.danger:hover {
            background: #c82333;
        }
        button.success {
            background: #28a745;
        }
        button.success:hover {
            background: #218838;
        }
        .loading {
            text-align: center;
            padding: 20px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Fix File Links</h1>
        <p class="subtitle">Update file URLs to include port 8080</p>

<?php

try {
    // Connect to database
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    if ($action === 'fix') {
        // Perform the fix
        echo '<div class="status-box info"><strong>🔄 Updating file links...</strong></div>';
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->query("
            SELECT id, file_link 
            FROM enquiry_form 
            WHERE file_link IS NOT NULL 
            AND file_link != ''
            AND file_link LIKE '%localhost/enquiry-form%'
            AND file_link NOT LIKE '%localhost:8080%'
        ");
        
        $enquiries = $stmt->fetchAll();
        $updatedCount = 0;
        
        $updateStmt = $pdo->prepare("UPDATE enquiry_form SET file_link = :new_link WHERE id = :id");
        
        foreach ($enquiries as $enquiry) {
            $newLink = str_replace(
                'http://localhost/enquiry-form',
                'http://localhost:8080/enquiry-form',
                $enquiry['file_link']
            );
            
            $updateStmt->execute([
                'new_link' => $newLink,
                'id' => $enquiry['id']
            ]);
            
            $updatedCount++;
        }
        
        $pdo->commit();
        
        echo '<div class="status-box success">';
        echo '<strong>✅ Success!</strong><br>';
        echo "Updated {$updatedCount} file links to use port 8080";
        echo '</div>';
        
        echo '<p><a href="?action=view"><button>View Current Status</button></a></p>';
        
    } else {
        // View current status
        $stmt = $pdo->query("
            SELECT id, company_name, file_link 
            FROM enquiry_form 
            WHERE file_link IS NOT NULL 
            AND file_link != ''
            ORDER BY id DESC
            LIMIT 50
        ");
        
        $enquiries = $stmt->fetchAll();
        $totalCount = count($enquiries);
        
        // Count how many need fixing
        $needsFixing = 0;
        $needsFixingList = [];
        foreach ($enquiries as $enquiry) {
            if (strpos($enquiry['file_link'], 'localhost/enquiry-form') !== false) {
                $needsFixing++;
                $needsFixingList[] = $enquiry;
            }
        }
        
        echo '<div class="status-box info">';
        echo "<strong>📊 Status:</strong><br>";
        echo "Total enquiries with files: {$totalCount}<br>";
        echo "Files needing port fix: {$needsFixing}";
        echo '</div>';
        
        if ($needsFixing > 0) {
            echo '<div class="status-box warning">';
            echo '<strong>⚠️ Action Required</strong><br>';
            echo "There are {$needsFixing} file links that need to be updated to include port 8080.";
            echo '</div>';
            
            echo '<h2>Files That Need Fixing</h2>';
            echo '<table>';
            echo '<thead><tr><th>ID</th><th>Company</th><th>Current URL</th><th>Will Become</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($needsFixingList as $enquiry) {
                $newLink = str_replace(
                    'http://localhost/enquiry-form',
                    'http://localhost:8080/enquiry-form',
                    $enquiry['file_link']
                );
                
                echo '<tr>';
                echo '<td>#' . htmlspecialchars($enquiry['id']) . '</td>';
                echo '<td>' . htmlspecialchars($enquiry['company_name']) . '</td>';
                echo '<td class="old-link">' . htmlspecialchars($enquiry['file_link']) . '</td>';
                echo '<td class="new-link">' . htmlspecialchars($newLink) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            
            echo '<div style="margin-top: 30px;">';
            echo '<p><strong>Ready to fix?</strong> This will update all file links to use <code>http://localhost:8080/...</code></p>';
            echo '<a href="?action=fix"><button class="success">✅ Fix All File Links</button></a>';
            echo '<a href="?action=view"><button>🔄 Refresh</button></a>';
            echo '</div>';
            
        } else {
            echo '<div class="status-box success">';
            echo '<strong>✅ All Good!</strong><br>';
            echo 'All file links are using the correct port (8080).';
            echo '</div>';
            
            if ($totalCount > 0) {
                echo '<h2>Recent Files (Showing up to 10)</h2>';
                echo '<table>';
                echo '<thead><tr><th>ID</th><th>Company</th><th>File Link</th></tr></thead>';
                echo '<tbody>';
                
                $shown = 0;
                foreach ($enquiries as $enquiry) {
                    echo '<tr>';
                    echo '<td>#' . htmlspecialchars($enquiry['id']) . '</td>';
                    echo '<td>' . htmlspecialchars($enquiry['company_name']) . '</td>';
                    echo '<td><a href="' . htmlspecialchars($enquiry['file_link']) . '" target="_blank">' . 
                         htmlspecialchars(basename($enquiry['file_link'])) . '</a></td>';
                    echo '</tr>';
                    
                    $shown++;
                    if ($shown >= 10) break;
                }
                
                echo '</tbody></table>';
            }
        }
    }
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<div class="status-box error">';
    echo '<strong>❌ Database Error:</strong><br>';
    echo htmlspecialchars($e->getMessage());
    echo '</div>';
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<div class="status-box error">';
    echo '<strong>❌ Error:</strong><br>';
    echo htmlspecialchars($e->getMessage());
    echo '</div>';
}

?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd; color: #666;">
            <p><strong>What this tool does:</strong></p>
            <ul>
                <li>Finds all file links in the database that are missing port 8080</li>
                <li>Updates them from <code>http://localhost/...</code> to <code>http://localhost:8080/...</code></li>
                <li>Makes files accessible from your XAMPP server</li>
            </ul>
        </div>
    </div>
</body>
</html>

