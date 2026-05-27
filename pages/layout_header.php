<?php
// pages/layout_header.php
// 사용 전 $title 변수 설정 필요 (없으면 기본값 사용)
$page_title = ($title ?? 'Marketo Automation') . ' — Marketo Automation';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="<?= APP_URL ?>">Marketo Automation</a>
    <div class="navbar-nav flex-row gap-3">
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/segments')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/segments">세그먼트</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/campaigns')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/campaigns">캠페인</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/schedules')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/schedules">발송 스케줄</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/calendar')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/calendar">캘린더</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/dashboard/results')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/dashboard/results">발송 결과</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/marketo-usage')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/marketo-usage">API 사용량</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/isolation-queue')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/isolation-queue">격리 큐</a>
    </div>
  </div>
</nav>
<div class="container-fluid px-4">
