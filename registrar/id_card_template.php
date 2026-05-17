<?php
/**
 * Student ID Card Template
 * Standard CR80 Size: 85.6mm x 54mm
 */
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap');

    :root {
        --id-width: 85.6mm;
        --id-height: 54mm;
        --primary-blue: #1e3a8a;
        --accent-gold: #fbbf24;
        --glass: rgba(255, 255, 255, 0.1);
        --text-dark: #0f172a;
    }

    .id-card-wrapper {
        display: flex;
        flex-direction: column;
        gap: 20px;
        align-items: center;
        padding: 20px;
        background: #f1f5f9;
        font-family: 'Outfit', sans-serif;
    }

    .id-card {
        width: var(--id-width);
        height: var(--id-height);
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: 120px 1fr;
        border: 1px solid #e2e8f0;
    }

    .id-card.back {
        display: flex;
        flex-direction: column;
        padding: 15px;
        grid-template-columns: 1fr;
    }

    /* Background patterns */
    .id-card::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -20%;
        width: 150%;
        height: 150%;
        background: radial-gradient(circle, var(--primary-blue) 0%, transparent 70%);
        opacity: 0.05;
        pointer-events: none;
    }

    .sidebar {
        background: var(--primary-blue);
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 15px 10px;
        color: white;
        position: relative;
        z-index: 1;
    }

    .school-logo {
        width: 50px;
        height: 50px;
        background: white;
        border-radius: 50%;
        margin-bottom: 20px;
        padding: 5px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .student-photo-container {
        width: 85px;
        height: 85px;
        border: 3px solid white;
        border-radius: 8px;
        overflow: hidden;
        background: #f8fafc;
        box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.2);
    }

    .student-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .main-content {
        padding: 15px 20px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
    }

    .school-name {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--primary-blue);
        letter-spacing: 0.5px;
        margin-bottom: 10px;
    }

    .student-name {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-dark);
        margin-bottom: 4px;
        line-height: 1.2;
    }

    .student-title {
        font-size: 11px;
        font-weight: 500;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 15px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 8px;
        text-transform: uppercase;
        color: var(--muted);
        font-weight: 600;
    }

    .info-value {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-dark);
    }

    .qr-container {
        position: absolute;
        bottom: 15px;
        right: 20px;
        width: 45px;
        height: 45px;
    }

    .qr-code {
        width: 100%;
        height: 100%;
    }

    /* Back of Card */
    .emergency-contact {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #e2e8f0;
    }

    .signature-area {
        margin-top: auto;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }

    .signature-box {
        text-align: center;
        width: 120px;
    }

    .signature-line {
        border-top: 1px solid var(--text-dark);
        margin-top: 20px;
        padding-top: 4px;
        font-size: 9px;
        font-weight: 600;
    }

    .terms {
        font-size: 7px;
        color: var(--muted);
        line-height: 1.4;
        margin-top: 5px;
    }

    @media print {
        body { margin: 0; background: white; }
        .id-card-wrapper { padding: 0; background: transparent; }
        .id-card { box-shadow: none; border: 0.5px solid #ccc; break-inside: avoid; }
    }
</style>

<div class="id-card-wrapper">
    <!-- FRONT -->
    <div class="id-card">
        <div class="sidebar">
            <div class="school-logo">
                <img src="<?= url_for('/assets/logo.png') ?>" alt="Logo" style="width: 100%; height: 100%;">
            </div>
            <div class="student-photo-container">
                <img src="<?= $student['photo_path'] ?? url_for('/assets/default-student.png') ?>" class="student-photo">
            </div>
        </div>
        <div class="main-content">
            <div class="school-name">Malolos Marine Fishery School</div>
            <div class="student-name"><?= $student['student_name'] ?></div>
            <div class="student-title">Student Identification</div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Student ID</span>
                    <span class="info-value"><?= $student['student_id'] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Grade/Level</span>
                    <span class="info-value"><?= $student['grade_level'] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">LRN</span>
                    <span class="info-value"><?= $student['lrn'] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">School Year</span>
                    <span class="info-value"><?= $student['school_year'] ?></span>
                </div>
            </div>

            <div class="qr-container">
                <img src="<?= $student['qr_code_path'] ?>" class="qr-code">
            </div>
        </div>
    </div>

    <!-- BACK -->
    <div class="id-card back">
        <div class="school-name" style="text-align: center; margin-bottom: 5px;">Emergency Contact Information</div>
        <div class="emergency-contact">
            <div class="info-item">
                <span class="info-label">Guardian Name</span>
                <span class="info-value"><?= $student['guardian_name'] ?></span>
            </div>
            <div class="info-item" style="margin-top: 5px;">
                <span class="info-label">Contact Number</span>
                <span class="info-value"><?= $student['guardian_contact'] ?></span>
            </div>
            <div class="info-item" style="margin-top: 5px;">
                <span class="info-label">Address</span>
                <span class="info-value" style="font-size: 9px;"><?= $student['address'] ?></span>
            </div>
        </div>

        <div class="terms">
            This card is non-transferable and must be worn at all times while within school premises. If found, please return to the school administration office.
        </div>

        <div class="signature-area">
            <div class="signature-box">
                <div class="signature-line">Student's Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">School Principal</div>
            </div>
        </div>
    </div>
</div>
