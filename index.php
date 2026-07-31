<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

$is_superuser = isset($_SESSION['superuser']) && (int)$_SESSION['superuser'] === 1;

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST']; 
$baseUrl = $protocol . $domainName . '/';

// Save QR Code AJAX Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_qr') {
    $id = intval($_POST['id']);
    $qrUrl = $_POST['qr_url'];

    if ($id > 0 && !empty($qrUrl)) {
        $stmt = $conn->prepare("UPDATE product_docs SET QR_Code = ? WHERE id = ?");
        $stmt->bind_param("si", $qrUrl, $id);
        $stmt->execute();
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}

// Update Product Document Details Handler (Superuser Only with Audit Logs)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product') {
    if (!$is_superuser) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $id         = intval($_POST['id']);
    $docProduct = trim($_POST['doc_product']);
    $docLink    = trim($_POST['link']);

    // Capture User Info & IP Address
    $updatedBy  = $_SESSION['useremail'] ?? ($_SESSION['username'] ?? 'Unknown Superuser');
    $updatedOn  = date('Y-m-d H:i:s');
    
    // IP resolution supporting proxies/Cloudflare
    $ipAddress  = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (strpos($ipAddress, ',') !== false) {
        $ipAddress = trim(explode(',', $ipAddress)[0]);
    }

    if ($id > 0 && !empty($docProduct) && !empty($docLink)) {
        $stmt = $conn->prepare("UPDATE product_docs SET Doc_Product = ?, TextURL = ?, Link = ?, Updated_By = ?, Updated_On = ?, Last_Access_IP = ? WHERE id = ?");
        $stmt->bind_param("ssssssi", $docProduct, $docLink, $docLink, $updatedBy, $updatedOn, $ipAddress, $id);
        
        if ($stmt->execute()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Failed to update record']);
    exit;
}

// Search & List Fetch AJAX Handler
if (isset($_GET['q'])) {
    $q = trim($_GET['q']);
    $results = [];

    if ($q !== '') {
        $stmt = $conn->prepare("SELECT id, Doc_Product, Doc_Type, Doc_Description, Folder_Path, TextURL, Link, QR_Code FROM product_docs WHERE Doc_Product LIKE CONCAT('%', ?, '%') ORDER BY Doc_Product ASC");
        $stmt->bind_param("s", $q);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[] = $row;
        }
    } else {
        $res = $conn->query("SELECT id, Doc_Product, Doc_Type, Doc_Description, Folder_Path, TextURL, Link, QR_Code FROM product_docs ORDER BY Doc_Product ASC");
        while ($row = $res->fetch_assoc()) {
            $results[] = $row;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}

// Initial Data Loading
$initialQuery = $conn->query("SELECT id, Doc_Product, Doc_Type, Doc_Description, Folder_Path, TextURL, Link, QR_Code FROM product_docs ORDER BY Doc_Product ASC");
$medicines = [];
while ($row = $initialQuery->fetch_assoc()) {
    $medicines[] = $row;
}

$hasData = !empty($medicines);
$firstMedicine = $hasData ? $medicines[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Highnoon | Product Documents Portal</title>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        :root {
            --hn-orange: #f25a22;
            --hn-orange-hover: #d94b18;
            --hn-bg: #f4f6f9;
            --hn-card-bg: #ffffff;
            --hn-text-dark: #2c3e50;
            --hn-text-muted: #8c98a4;
            --hn-border: #edf2f7;
            --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font-main); background-color: var(--hn-bg); color: var(--hn-text-dark); line-height: 1.5; }
        .header { background-color: var(--hn-orange); height: 85px; padding: 0 40px; display: flex; align-items: center; justify-content: space-between; }
        .header-logo { font-size: 37px; font-weight: 800; color: #ffffff; text-decoration: none; }
        .user-nav { display: flex; align-items: center; gap: 15px; color: #ffffff; }
        .user-info { font-size: 14px; font-weight: 500; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; transition: background 0.2s ease; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.35); }
        .main-container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        .breadcrumb { font-size: 13px; color: var(--hn-text-muted); margin-bottom: 20px; }
        .breadcrumb span { color: var(--hn-orange); font-weight: 500; }
        .split-layout { display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start; }

        .product-list-card { background: var(--hn-card-bg); border-radius: 12px; border: 1px solid var(--hn-border); padding: 20px; display: flex; flex-direction: column; gap: 15px; height: 820px; }
        .card-heading { font-size: 18px; font-weight: 700; border-bottom: 2px solid var(--hn-border); padding-bottom: 10px; }
        .search-box input { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; outline: none; }
        .product-items-container { overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
        .product-item { padding: 12px 14px; border-radius: 8px; background: #f8fafc; border: 1px solid var(--hn-border); cursor: pointer; font-size: 14px; font-weight: 600; }
        .product-item.active { background: var(--hn-orange); color: #ffffff; }

        .document-viewer-card { background: var(--hn-card-bg); border-radius: 12px; border: 1px solid var(--hn-border); padding: 24px; display: flex; flex-direction: column; gap: 20px; height: 820px; }
        .doc-header-meta { display: flex; justify-content: space-between; border-bottom: 1px solid var(--hn-border); padding-bottom: 16px; }
        .meta-details { display: flex; flex-direction: column; gap: 12px; }
        .meta-row { display: flex; align-items: center; gap: 10px; font-size: 15px; }
        .meta-label { font-weight: 700; min-width: 120px; }
        .meta-value { display: flex; align-items: center; gap: 8px; }
        .meta-value a { color: var(--hn-orange); text-decoration: none; font-weight: 500; }

        .btn-edit-doc {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff3ed;
            color: var(--hn-orange);
            border: 1px solid #ffd8c7;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-edit-doc:hover {
            background: var(--hn-orange);
            color: #ffffff;
            border-color: var(--hn-orange);
        }

        .qr-code-block { display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .qr-box { 
            width: 110px; 
            height: 110px; 
            border: 1px solid var(--hn-border); 
            border-radius: 8px; 
            padding: 4px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            background: #fff; 
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .qr-box:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .qr-box img { width: 100%; height: 100%; object-fit: contain; }
        .qr-label { font-size: 11px; font-weight: 700; color: var(--hn-text-muted); }

        .document-frame-wrapper { flex: 1; border-radius: 8px; border: 1px solid var(--hn-border); background: #f8fafc; display: flex; align-items: center; justify-content: center; position: relative; }
        iframe { width: 100%; height: 100%; border: none; }
        .no-data-text { color: var(--hn-text-muted); font-size: 18px; font-weight: 600; }

        /* Modal Base Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: #ffffff;
            padding: 24px 28px;
            border-radius: 12px;
            width: 340px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .modal-content-wide {
            width: 520px;
            max-width: 90vw;
            padding: 30px;
            text-align: left;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--hn-text-dark);
            border-bottom: 2px solid var(--hn-border);
            padding-bottom: 12px;
        }

        .download-btn-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-download {
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid var(--hn-border);
            background: #f8fafc;
            color: var(--hn-text-dark);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-download:hover {
            background: var(--hn-orange);
            color: #ffffff;
            border-color: var(--hn-orange);
        }

        .btn-close {
            background: transparent;
            border: none;
            color: var(--hn-text-muted);
            font-size: 13px;
            cursor: pointer;
            margin-top: 6px;
            align-self: center;
        }
        .btn-close:hover { text-decoration: underline; }

        .form-group-modal { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
        .form-group-modal label { font-size: 13px; font-weight: 700; color: var(--hn-text-dark); }
        .form-group-modal input { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s ease; }
        .form-group-modal input:focus { border-color: var(--hn-orange); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 8px; }
    </style>
</head>
<body>

    <header class="header">
        <a href="#" class="header-logo">Highnoon</a>
        <div class="user-nav">
            <span class="user-info"><?= htmlspecialchars($_SESSION['username'] ?? $_SESSION['useremail']) ?></span>
            <?php if ($is_superuser): ?>
                <a href="add_user.php" class="btn-logout" style="background: rgba(255, 255, 255, 0.3);">+ Add User</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="breadcrumb"><span>Highnoon</span> / Product Documents</div>

        <div class="split-layout">
            <div class="product-list-card">
                <h3 class="card-heading">Product List</h3>
                <div class="search-box">
                    <input type="text" id="search-input" placeholder="Search product..." autocomplete="off">
                </div>
                <div class="product-items-container" id="product-list">
                    <?php if ($hasData): ?>
                        <?php foreach ($medicines as $index => $med): ?>
                        <div class="product-item <?= $index === 0 ? 'active' : '' ?>" onclick='selectProduct(<?= json_encode($med) ?>, this)'>
                            <?= htmlspecialchars($med['Doc_Product']) ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: var(--hn-text-muted); font-size: 13px; text-align: center; padding: 10px;">No products found</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="document-viewer-card">
                <div class="doc-header-meta">
                    <div class="meta-details">
                        <div class="meta-row">
                            <span class="meta-label">Product Name:</span>
                            <div class="meta-value">
                                <span id="display-product-name"><?= $hasData ? htmlspecialchars($firstMedicine['Doc_Product']) : 'No Products Available' ?></span>
                                <?php if ($is_superuser): ?>
                                    <button class="btn-edit-doc" id="btn-open-edit-modal" onclick="openEditModal()" title="Edit Document Details" style="<?= $hasData ? 'display:inline-flex;' : 'display:none;' ?>">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">URL:</span>
                            <span class="meta-value" id="url-container">
                                <?php if ($hasData): ?>
                                    <a id="url-link" href="<?= htmlspecialchars($firstMedicine['Link']) ?>" target="_blank">
                                        <?= htmlspecialchars($firstMedicine['TextURL'] ?: $firstMedicine['Link']) ?>
                                    </a>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="qr-code-block">
                        <div class="qr-box" onclick="openDownloadModal()" title="Click to download QR Code">
                            <span id="qr-placeholder" style="<?= $hasData ? 'display:none;' : 'display:block;' ?>">QR CODE</span>
                            <img id="qr-image" src="" alt="QR Code" style="<?= $hasData ? 'display:block;' : 'display:none;' ?>">
                        </div>
                        <span class="qr-label">QR CODE</span>
                    </div>
                </div>

                <div class="document-frame-wrapper">
                    <span id="doc-placeholder" class="no-data-text" style="<?= $hasData ? 'display:none;' : 'display:block;' ?>">Document</span>
                    <iframe id="pdf-frame" src="" type="application/pdf" style="<?= $hasData ? 'display:block;' : 'display:none;' ?>"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Download Modal -->
    <div class="modal-overlay" id="qr-modal" onclick="closeDownloadModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-title" style="text-align:center;">Download QR Code</div>
            <div class="download-btn-group">
                <button class="btn-download" onclick="downloadQR('png')">Download PNG</button>
                <button class="btn-download" onclick="downloadQR('jpg')">Download JPG</button>
                <button class="btn-download" onclick="downloadQR('pdf')">Download PDF</button>
            </div>
            <button class="btn-close" onclick="closeDownloadModal()">Cancel</button>
        </div>
    </div>

    <!-- Expanded Superuser Edit Product Modal -->
    <?php if ($is_superuser): ?>
    <div class="modal-overlay" id="edit-doc-modal" onclick="closeEditModal(event)">
        <div class="modal-content modal-content-wide" onclick="event.stopPropagation()">
            <div class="modal-title">Edit Document Details</div>
            
            <form id="edit-doc-form" onsubmit="saveProductDetails(event)">
                <input type="hidden" id="edit-doc-id">

                <div class="form-group-modal">
                    <label for="edit-doc-name">Product Name</label>
                    <input type="text" id="edit-doc-name" required autocomplete="off" placeholder="e.g. airtal">
                </div>

                <div class="form-group-modal">
                    <label for="edit-doc-link">Document Link / Path</label>
                    <input type="text" id="edit-doc-link" required autocomplete="off" placeholder="e.g. leaflets/Airtal/Airtal%20Insert.pdf">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-close" onclick="closeEditModal()" style="margin:0;">Cancel</button>
                    <button type="submit" class="btn-download" style="background: var(--hn-orange); color: #fff; border-color: var(--hn-orange);">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const BASE_URL = "<?= $baseUrl ?>";
        const searchInput = document.getElementById('search-input');
        const productList = document.getElementById('product-list');
        const displayName = document.getElementById('display-product-name');
        const urlContainer = document.getElementById('url-container');
        const qrPlaceholder = document.getElementById('qr-placeholder');
        const qrImage = document.getElementById('qr-image');
        const docPlaceholder = document.getElementById('doc-placeholder');
        const pdfFrame = document.getElementById('pdf-frame');
        const qrModal = document.getElementById('qr-modal');
        const btnOpenEditModal = document.getElementById('btn-open-edit-modal');
        const editDocModal = document.getElementById('edit-doc-modal');

        let currentProduct = null;

        // Load PDF via Base64 Inline Data URI to Bypass All Browser Caching Completely
        function loadInlinePDF(pdfUrl) {
            let fetchUrl = pdfUrl.startsWith('http') ? pdfUrl : BASE_URL + pdfUrl;
            
            // Add cache-busting timestamp parameter to fetch
            fetchUrl += (fetchUrl.includes('?') ? '&' : '?') + 'cache_bust=' + new Date().getTime();

            fetch(fetchUrl, { cache: 'no-store' })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.blob();
                })
                .then(blob => {
                    const reader = new FileReader();
                    reader.onloadend = function() {
                        pdfFrame.src = reader.result + '#toolbar=0';
                        pdfFrame.style.display = 'block';
                        docPlaceholder.style.display = 'none';
                    };
                    reader.readAsDataURL(blob);
                })
                .catch(err => {
                    // Fallback to direct src if blob conversion fails
                    pdfFrame.src = fetchUrl + '#toolbar=0';
                    pdfFrame.style.display = 'block';
                    docPlaceholder.style.display = 'none';
                });
        }

        function updateOrSaveQRCode(product) {
            if (!product || !product.Link) {
                qrImage.style.display = 'none';
                qrPlaceholder.style.display = 'block';
                return;
            }

            let docTargetUrl = product.Link.startsWith('http') ? product.Link : BASE_URL + product.Link;
            const generatedQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(docTargetUrl);

            qrImage.src = generatedQrUrl;
            qrImage.style.display = 'block';
            qrPlaceholder.style.display = 'none';

            if (!product.QR_Code || product.QR_Code.trim() === '') {
                product.QR_Code = generatedQrUrl;
                let formData = new FormData();
                formData.append('action', 'save_qr');
                formData.append('id', product.id);
                formData.append('qr_url', generatedQrUrl);

                fetch('index.php', { method: 'POST', body: formData });
            }
        }

        function selectProduct(product, element) {
            document.querySelectorAll('.product-item').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');

            currentProduct = product;

            if (product) {
                displayName.textContent = product.Doc_Product;
                let linkText = product.TextURL || product.Link;
                urlContainer.innerHTML = `<a href="${product.Link}" target="_blank">${linkText}</a>`;
                
                loadInlinePDF(product.Link);

                if (btnOpenEditModal) btnOpenEditModal.style.display = 'inline-flex';

                updateOrSaveQRCode(product);
            } else {
                setEmptyState();
            }
        }

        function setEmptyState() {
            currentProduct = null;
            displayName.textContent = 'No Products Available';
            urlContainer.innerHTML = '<span>-</span>';
            pdfFrame.src = '';
            pdfFrame.style.display = 'none';
            docPlaceholder.style.display = 'block';
            qrImage.src = '';
            qrImage.style.display = 'none';
            qrPlaceholder.style.display = 'block';
            if (btnOpenEditModal) btnOpenEditModal.style.display = 'none';
        }

        function openDownloadModal() {
            if (!currentProduct || !qrImage.src) return;
            qrModal.style.display = 'flex';
        }

        function closeDownloadModal(e) {
            qrModal.style.display = 'none';
        }

        function downloadQR(format) {
            if (!qrImage.src || !currentProduct) return;

            const cleanName = currentProduct.Doc_Product.replace(/[^a-z0-9]/gi, '_').toLowerCase();
            const fileName = `${cleanName}_qr.${format}`;

            const img = new Image();
            img.crossOrigin = 'Anonymous';
            img.src = qrImage.src;

            img.onload = function() {
                const canvas = document.createElement('canvas');
                canvas.width = img.width;
                canvas.height = img.height;
                const ctx = canvas.getContext('2d');

                if (format === 'jpg') {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                }

                ctx.drawImage(img, 0, 0);

                if (format === 'pdf') {
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF({ orientation: 'portrait', unit: 'px', format: [300, 300] });
                    const imgData = canvas.toDataURL('image/png');
                    pdf.addImage(imgData, 'PNG', 0, 0, 300, 300);
                    pdf.save(fileName);
                } else {
                    const mimeType = format === 'jpg' ? 'image/jpeg' : 'image/png';
                    const dataUrl = canvas.toDataURL(mimeType);

                    const link = document.createElement('a');
                    link.href = dataUrl;
                    link.download = fileName;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }

                closeDownloadModal();
            };
        }

        // --- Superuser Edit Functions ---
        function openEditModal() {
            if (!currentProduct || !editDocModal) return;
            document.getElementById('edit-doc-id').value = currentProduct.id;
            document.getElementById('edit-doc-name').value = currentProduct.Doc_Product;
            document.getElementById('edit-doc-link').value = currentProduct.Link;
            editDocModal.style.display = 'flex';
        }

        function closeEditModal(e) {
            if (editDocModal) editDocModal.style.display = 'none';
        }

        function saveProductDetails(e) {
            e.preventDefault();
            const id = document.getElementById('edit-doc-id').value;
            const docName = document.getElementById('edit-doc-name').value.trim();
            const docLink = document.getElementById('edit-doc-link').value.trim();

            let formData = new FormData();
            formData.append('action', 'update_product');
            formData.append('id', id);
            formData.append('doc_product', docName);
            formData.append('link', docLink);

            fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        currentProduct.Doc_Product = docName;
                        currentProduct.TextURL = docLink;
                        currentProduct.Link = docLink;
                        currentProduct.QR_Code = ''; // Reset cached QR so it regenerates for new link

                        // Refresh active product text in list
                        const activeItem = document.querySelector('.product-item.active');
                        if (activeItem) activeItem.textContent = docName;
                        
                        selectProduct(currentProduct, activeItem);
                        closeEditModal();
                    } else {
                        alert(data.message || 'Error updating product');
                    }
                });
        }

        <?php if ($hasData): ?>
        selectProduct(<?= json_encode($firstMedicine) ?>, productList.firstElementChild);
        <?php else: ?>
        setEmptyState();
        <?php endif; ?>

        searchInput.addEventListener('input', function() {
            let query = this.value.trim();
            fetch('index.php?q=' + encodeURIComponent(query))
                .then(res => res.json())
                .then(data => {
                    productList.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach((item, idx) => {
                            let div = document.createElement('div');
                            div.className = 'product-item' + (idx === 0 ? ' active' : '');
                            div.textContent = item.Doc_Product;
                            div.onclick = function() { selectProduct(item, this); };
                            productList.appendChild(div);
                        });
                        selectProduct(data[0], productList.firstElementChild);
                    } else {
                        productList.innerHTML = '<div style="color: var(--hn-text-muted); font-size: 13px; text-align: center; padding: 10px;">No products found</div>';
                        setEmptyState();
                    }
                });
        });
    </script>
</body>
</html>