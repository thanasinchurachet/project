<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start(); // เริ่มเซสชันเพียงครั้งเดียว
require_once 'db.php'; // เชื่อมต่อฐานข้อมูล
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BinBuddy - แยกขยะอย่างชาญฉลาด</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- เพิ่มลิงก์ Google Fonts สำหรับฟอนต์ Kanit -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    
    <!-- เพิ่ม CSS ที่กำหนดฟอนต์ Kanit -->
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
    </style>

    <!-- JS -->
    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>
    

    <!-- CSS เพิ่มเติม -->
    <style>
        .rounded-xl {
            border-radius: 3rem;
        }
        .py-16 {
            padding-top: 0rem;
            padding-bottom: 4rem;
        }
        .rounded-lg {
            border-radius: 0.2rem;
        }
        .scroll-indicator {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-20px);
            }
            60% {
                transform: translateY(-10px);
            }
        }

        /* Updated compact nav styles */
        .nav-logo {
            width: 150px;
            height: 150px;
            object-fit: contain;
        }

        .nav-container {
            height: 90px;
            display: flex;
            align-items: center;
        }

        .hero-gradient {
            background: linear-gradient(135deg, #22c55e 0%, #059669 100%);
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .custom-shadow {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        nav {
            z-index: 40; /* อยู่เหนือเนื้อหาอื่นแต่ต่ำกว่าแชท */
        }
        #chatButton, #chatWindow {
            z-index: 50; /* แชทอยู่ชั้นสูงสุด */
        }
    </style>
    <style>
        @keyframes pulse-slow {
            0%, 100% { opacity: 0.1; transform: scale(1); }
            50% { opacity: 0.15; transform: scale(1.05); }
        }
        @keyframes underline {
            0% { width: 0; left: 0; }
            100% { width: 100%; left: 0; }
        }
        @keyframes slide-up {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .animate-pulse-slow {
            animation: pulse-slow 8s infinite;
        }
        .animate-underline {
            animation: underline 1.5s ease-out forwards;
        }
        .animate-slide-up {
            animation: slide-up 1s ease-out;
        }
        .animate-float {
            animation: float 4s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Compact Navigation -->
    <?php include 'nav.php'; ?>

    <!-- Hero Section - Modern Redesign -->
    <div class="relative min-h-[70vh] overflow-hidden bg-gradient-to-br from-emerald-700 via-green-600 to-emerald-200 text-white">
        <!-- Animated Background Elements -->
        <div class="absolute inset-0" style="background-color: #059669;">
            <div class="absolute w-96 h-96 bg-emerald-500/20 rounded-full -top-48 -left-48 animate-pulse-slow"></div>
            <div class="absolute w-64 h-64 bg-white/10 rounded-full top-1/2 right-0 animate-float"></div>
        </div>

        <div class="container mx-auto px-6 py-12 relative z-10" style="background-color: #059669;">
            <div class="flex flex-col lg:flex-row items-center gap-12">
                <!-- Content Section -->
                <div class="lg:w-1/2 text-center lg:text-left space-y-8">
                    <div class="animate-slide-up">
                        <h1 class="text-4xl md:text-6xl font-extrabold mb-6 leading-tight">
                            <span class="bg-gradient-to-r from-emerald-300 to-green-100 bg-clip-text text-transparent drop-shadow-2xl">
                                BinBuddy
                            </span>
                            <span class="block mt-2 text-emerald-100 text-3xl md:text-4xl font-light">
                                เวอร์ชันทดลอง
                            </span>
                        </h1>
                        <p class="text-xl text-emerald-100 mb-8 drop-shadow-md max-w-2xl">
                            ระบบต้นแบบสำหรับทดสอบการจำแนกขยะด้วยปัญญาประดิษฐ์
                            <span class="block mt-2 text-sm text-emerald-200/90">Powered by Deep Learning Technology</span>
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-6 justify-center animate-slide-up">
    <!-- ปุ่มบน (สีขาว) -->
    <a href="scan.php" 
       class="group relative px-12 py-5 bg-white hover:bg-gray-50 text-gray-800 font-bold rounded-2xl shadow-lg transform transition-all duration-300 hover:-translate-y-1 hover:scale-105 border-2 border-gray-300 hover:border-gray-400">
        <span class="relative z-10 flex items-center gap-3 text-lg">
            <i class="fas fa-play-circle text-2xl text-emerald-500"></i>
            เริ่มต้นใช้งานทันที
        </span>
    </a>
</div>

                    <!-- Scroll Indicator -->
                    <div class="mt-12 animate-bounce">
                        <div class="relative flex flex-col items-center justify-center gap-2">
                            <div class="w-12 h-12 border-2 border-emerald-300/50 rounded-full flex items-center justify-center relative">
                                <div class="absolute w-full h-full border-2 border-emerald-300/20 rounded-full animate-ping"></div>
                                <i class="fas fa-chevron-down text-emerald-300 text-lg animate-bounce"></i>
                            </div>
                            <span class="text-sm font-medium text-emerald-200/90 tracking-wide">
                                เลื่อนเพื่อสำรวจฟีเจอร์
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Image Section -->
                <div class="lg:w-1/2 relative animate-float">
                    <div class="relative mx-auto max-w-xl transform perspective-1000">
                        <div class="relative rounded-3xl overflow-hidden shadow-2xl border-4 border-emerald-300/20 transform rotate-3 hover:rotate-0 transition-transform duration-500">
                            <img src="./img/banner1.jpeg" 
                                 alt="หน้าจอทดลองใช้งาน" 
                                 class="w-full h-full object-cover transform hover:scale-105 transition-transform duration-300">
                        </div>
                        <div class="absolute -top-6 -right-6 bg-gradient-to-br from-emerald-500 to-green-600 px-6 py-2 rounded-full text-sm font-bold text-white shadow-lg flex items-center gap-2">
                            <i class="fas fa-flask text-lg"></i>
                            เวอร์ชันทดลอง
                        </div>
                        <div class="absolute -bottom-8 -left-8 w-32 h-32 bg-emerald-500/10 rounded-full blur-xl"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div id="features" class="py-20 bg-white">
        <div class="container mx-auto px-6">
            <h2 class="text-3xl font-bold text-center text-gray-800 mb-16">ฟีเจอร์หลัก</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
                <!-- AI Scanner -->
                <div class="text-center p-6 bg-white rounded-lg shadow-lg hover:shadow-xl transition duration-300" style="border-radius: 10%;">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-camera text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-4">สแกนขยะด้วย AI</h3>
                    <p class="text-gray-600">
                        ใช้กล้องถ่ายภาพขยะ และปล่อยให้ AI ช่วยจำแนกประเภทขยะและแนะนำวิธีจัดการที่เหมาะสม
                    </p>
                    <a href="scan.php" class="mt-4 inline-block text-green-600 hover:text-green-700">เริ่มสแกน →</a>
                </div>

                <!-- Location Map -->
                <div class="text-center p-6 bg-white rounded-lg shadow-lg hover:shadow-xl transition duration-300" style="border-radius: 10%;">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-map-marker-alt text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-4">แผนที่จุดทิ้งขยะ</h3>
                    <p class="text-gray-600">
                        ค้นหาจุดทิ้งขยะใกล้คุณ พร้อมข้อมูลประเภทขยะที่รับและเวลาทำการ
                    </p>
                    <a href="locations.php" class="mt-4 inline-block text-green-600 hover:text-green-700">ดูแผนที่ →</a>
                </div>

                <!-- Community Share -->
                <div class="text-center p-6 bg-white rounded-lg shadow-lg hover:shadow-xl transition duration-300" style="border-radius: 10%;">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-share-alt text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-4">แบ่งปันความรู้</h3>
                    <p class="text-gray-600">
                        แบ่งปันเทคนิคการจัดการขยะ และเรียนรู้จากชุมชน BinBuddy
                    </p>
                    <a href="share1.php" class="mt-4 inline-block text-green-600 hover:text-green-700">ดูโพสต์ →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- How It Works -->
    <div class="py-20 bg-gray-50">
        <div class="container mx-auto px-4">
            <h2 class="text-3xl font-bold text-center text-gray-800 mb-16">วิธีการใช้งาน</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Step 1 - Register -->
                <div class="group bg-white rounded-xl shadow-lg hover:shadow-2xl overflow-hidden transition-all duration-300">
                    <div class="relative aspect-video bg-gray-100">
                        <div class="absolute inset-0 bg-gradient-to-t from-green-600/20 to-transparent z-10"></div>
                        <img 
                        src="./img/z3.png" 
                        alt="ขั้นตอนการสแกนขยะ" 
                        class="w-full h-full object-cover rounded-t-xl transform group-hover:scale-105 transition-transform duration-300">
                        <div class="absolute top-4 left-4 w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg border-2 border-white z-20">
                            1
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-3">สร้างบัญชี</h3>
                        <p class="text-gray-600">กรอกข้อมูลพื้นฐานเพื่อเริ่มต้นใช้งาน BinBuddy ได้ทันที</p>
                        <ul class="mt-4 space-y-2 text-sm text-gray-500">
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                ไม่เสียค่าใช้จ่าย
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Step 2 - Scan -->
                <div class="group bg-white rounded-xl shadow-lg hover:shadow-2xl overflow-hidden transition-all duration-300">
                    <div class="relative aspect-video bg-gray-100">
                        <div class="absolute inset-0 bg-gradient-to-t from-green-600/20 to-transparent z-10"></div>
                        <img 
                            src="./img/z.png" 
                            alt="ขั้นตอนการสแกนขยะ" 
                            class="w-full h-full object-cover rounded-t-xl transform group-hover:scale-105 transition-transform duration-300">
                        <div class="absolute top-4 left-4 w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg border-2 border-white z-20">
                            2
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-3">ถ่ายภาพขยะ</h3>
                        <p class="text-gray-600">ใช้กล้องมือถือถ่ายภาพขยะที่ต้องการจำแนก</p>
                        <ul class="mt-4 space-y-2 text-sm text-gray-500">
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                ถ่ายได้ทุกมุม
                            </li>
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                รองรับหลายวัตถุ
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Step 3 - Analysis -->
                <div class="group bg-white rounded-xl shadow-lg hover:shadow-2xl overflow-hidden transition-all duration-300">
                    <div class="relative aspect-video bg-gray-100">
                        <div class="absolute inset-0 bg-gradient-to-t from-green-600/20 to-transparent z-10"></div>
                        <img 
                        src="./img/z1.png" 
                        alt="ขั้นตอนการสแกนขยะ" 
                        class="w-full h-full object-cover rounded-t-xl transform group-hover:scale-105 transition-transform duration-300">
                        <div class="absolute top-4 left-4 w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg border-2 border-white z-20">
                            3
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-3">รับผลวิเคราะห์</h3>
                        <p class="text-gray-600">AI วิเคราะห์และแนะนำวิธีจัดการขยะที่เหมาะสม</p>
                        <ul class="mt-4 space-y-2 text-sm text-gray-500">
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                ผลลัพธ์ทันที
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Step 4 - Disposal -->
                <div class="group bg-white rounded-xl shadow-lg hover:shadow-2xl overflow-hidden transition-all duration-300">
                    <div class="relative aspect-video bg-gray-100">
                        <div class="absolute inset-0 bg-gradient-to-t from-green-600/20 to-transparent z-10"></div>
                        <img 
                        src="./img/z4.png" 
                        alt="ขั้นตอนการสแกนขยะ" 
                        class="w-full h-full object-cover rounded-t-xl transform group-hover:scale-105 transition-transform duration-300">
                        <div class="absolute top-4 left-4 w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg border-2 border-white z-20">
                            4
                        </div>
                    </div>
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-3">จัดการขยะ</h3>
                        <p class="text-gray-600">นำขยะไปทิ้งตามคำแนะนำที่จุดทิ้งขยะใกล้คุณ</p>
                        <ul class="mt-4 space-y-2 text-sm text-gray-500">
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                แผนที่นำทาง
                            </li>
                            <li class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                                </svg>
                                ข้อมูลจุดทิ้งขยะ
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Include Chat Component -->
    <?php include 'chat-button.php'; ?>

    <!-- Footer -->
    <footer class="bg-green-600 text-white py-12">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-8">
                <!-- Column 1: About -->
                <div class="col-span-2 sm:col-span-1">
                    <h4 class="text-xl font-bold mb-4">BinBuddy</h4>
                    <p class="text-green-100 text-sm md:text-base">
                        แอปพลิเคชันที่ช่วยให้การแยกขยะเป็นเรื่องง่าย 
                        ด้วยเทคโนโลยี AI ที่ช่วยให้คุณจัดการขยะได้อย่างถูกต้อง
                    </p>
                </div>

                <!-- Column 2: Menu -->
                <div class="col-span-1">
                    <h4 class="text-xl font-bold mb-4">เมนู</h4>
                    <ul class="space-y-2 text-sm md:text-base">
                        <li>
                            <a href="#" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-home mr-2"></i>หน้าแรก
                            </a>
                        </li>
                        <li>
                            <a href="#about" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>เกี่ยวกับเรา
                            </a>
                        </li>
                        <li>
                            <a href="#features" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-star mr-2"></i>ฟีเจอร์
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Column 3: Features -->
                <div class="col-span-1">
                    <h4 class="text-xl font-bold mb-4">ฟีเจอร์</h4>
                    <ul class="space-y-2 text-sm md:text-base">
                        <li>
                            <a href="scan.php" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-camera mr-2"></i>สแกนขยะ
                            </a>
                        </li>
                        <li>
                            <a href="locations.php" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-map-marker-alt mr-2"></i>แผนที่
                            </a>
                        </li>
                        <li>
                            <a href="share1.php" class="text-green-100 hover:text-white transition-colors duration-200 flex items-center">
                                <i class="fas fa-users mr-2"></i>ชุมชน
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Column 4: Contact -->
                <div class="col-span-2 sm:col-span-1">
                    <h4 class="text-xl font-bold mb-4">ติดต่อเรา</h4>
                    <div class="space-y-2 text-sm md:text-base">
                        <p class="text-green-100 flex items-center">
                            <i class="fas fa-envelope mr-2"></i>
                           binbuddy.sender@gmail.com
                        </p>
                       
                    </div>
                </div>
            </div>

            
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const notificationBtn = document.getElementById("notificationBtn");
            if (notificationBtn) {
                notificationBtn.addEventListener("click", function() {
                    const notificationToast = document.getElementById("notificationToast");
                    const notificationBadge = document.getElementById("notificationBadge");
    
                    // ตรวจสอบว่ามีการแสดง notificationToast และซ่อน notificationBadge
                    if (notificationToast && notificationBadge) {
                        console.log("Showing notificationToast...");
    
                        // แสดง notificationToast และซ่อน notificationBadge
                        notificationToast.classList.remove("hidden");
                        notificationBadge.classList.add("hidden");
    
                        // ใช้ setTimeout เพื่อซ่อน notificationToast หลังจาก 2 วินาที
                        setTimeout(function() {
                            console.log("This message shows after 2 seconds");
                        }, 2000);
                    } else {
                        console.log("Element not found: notificationToast or notificationBadge");
                    }
                });
            }
    
            // ซ่อน Notification Toast เมื่อคลิกภายนอก
            document.addEventListener("click", function(event) {
                const notificationToast = document.getElementById("notificationToast");
                
                // ตรวจสอบว่า คลิกอยู่นอก notificationToast หรือไม่
                if (notificationToast && !notificationToast.contains(event.target) && !event.target.closest('#notificationBtn')) {
                    notificationToast.classList.add("hidden");
                }
            });
        });
    </script>
</body>
</html>