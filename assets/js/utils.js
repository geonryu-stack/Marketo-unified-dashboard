// assets/js/utils.js — 프로젝트 공유 유틸리티 (모든 페이지에서 로드)

/** HTML 이스케이프 */
function escapeHtml(s) {
  if (s === undefined || s === null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 한국어 숫자 포맷 */
function fmt(n) { return Number(n).toLocaleString('ko-KR'); }

/** 퍼센트 포맷 */
function pct(n) { return (n == null ? '-' : Number(n).toFixed(1) + '%'); }

/** 비율에 따른 Bootstrap 색상 클래스 반환 */
function colorRate(rate, thresh_good, thresh_warn) {
  if (rate == null || rate === 0) return 'text-muted';
  if (rate >= thresh_good) return 'text-success';
  if (rate >= thresh_warn) return 'text-warning';
  return 'text-danger';
}
