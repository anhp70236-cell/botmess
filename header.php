<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iOS Việt - Devnvios</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: sans-serif; }
        .bg-ios-dark { background-color: #1a2b3c; } /* Màu header */
        .bg-ios-green { background-color: #10b981; } /* Màu banner */
        
        /* Hiệu ứng trái tim đập ở footer */
        .heartbeat {
            display: inline-block;
            color: #ff3b30;
            animation: heart-anim 1.4s infinite;
        }
        @keyframes heart-anim {
            0% { transform: scale(1); }
            15% { transform: scale(1.3); }
            30% { transform: scale(1); }
            45% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }
        .card-custom { border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .line-red { border-bottom: 2px solid #ef4444; width: 80px; }
        .line-gray { border-bottom: 2px solid #6b7280; width: 80px; }
    </style>
</head>
<body>
    <nav class="bg-ios-dark text-white p-4">
        <div class="max-w-4xl mx-auto flex justify-between items-center">
            <div class="text-2xl font-bold text-blue-400">iOSViệt.Co</div>
            <i class="fas fa-bars text-xl"></i>
        </div>
    </nav>
    
    <div class="bg-white border-b p-3">
        <div class="max-w-4xl mx-auto flex justify-end space-x-6 text-blue-600 font-semibold text-sm">
            <a href="#">Đăng Nhập</a>
            <a href="#">Đăng Ký</a>
        </div>
    </div>
    