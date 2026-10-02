// FAQ Accordion
document.querySelectorAll('.faq-question').forEach(q => {
q.addEventListener('click', () => {
const item = q.parentElement;
const isActive = item.classList.contains('active');
document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
if (!isActive) item.classList.add('active');
});
});
// Мобильное выдвижное меню (бургер) + выпадающие подменю
(function () {
var burger = document.querySelector('.nav-burger');
var navMenu = document.querySelector('.nav-menu');
var overlay = document.querySelector('.nav-overlay');
if (!burger || !navMenu || !overlay) return;

function isMobile() {
return window.matchMedia('(max-width: 968px)').matches;
}

function openMenu() {
burger.classList.add('active');
navMenu.classList.add('active');
overlay.classList.add('active');
burger.setAttribute('aria-expanded', 'true');
burger.setAttribute('aria-label', 'Закрыть меню');
navMenu.setAttribute('aria-hidden', 'false');
document.body.style.overflow = 'hidden';
}
function closeMenu() {
burger.classList.remove('active');
navMenu.classList.remove('active');
overlay.classList.remove('active');
burger.setAttribute('aria-expanded', 'false');
burger.setAttribute('aria-label', 'Открыть меню');
navMenu.setAttribute('aria-hidden', 'true');
document.body.style.overflow = '';
}

burger.addEventListener('click', function () {
navMenu.classList.contains('active') ? closeMenu() : openMenu();
});
overlay.addEventListener('click', closeMenu);
document.addEventListener('keydown', function (e) {
if (e.key === 'Escape') closeMenu();
});
// При расширении окна выше брейкпоинта — сбрасывать состояние drawer
window.addEventListener('resize', function () {
if (!isMobile()) closeMenu();
});

// Пункты с подменю: <button class="nav-toggle">
document.querySelectorAll('.nav-item.has-dropdown').forEach(function (li) {
var toggle = li.querySelector('.nav-toggle');
var link = li.querySelector(':scope > .nav-link'); // не трогаем ссылки внутри dropdown
if (toggle) {
toggle.addEventListener('click', function () {
var isOpen = li.classList.toggle('open');
toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
// На мобильном держим body-скролл заблокированным только если открыт drawer
});
}
// Перекрытие «родительского» пункта ссылкой вместо кнопки (если появится в разметке)
if (link && !toggle) {
link.addEventListener('click', function (e) {
if (isMobile() && !li.classList.contains('open')) {
e.preventDefault();
li.classList.add('open');
link.setAttribute('aria-expanded', 'true');
}
});
}
});

// Закрытие drawer при клике по конечной ссылке (только на мобильном)
navMenu.querySelectorAll('.dropdown-list a, .nav-item:not(.has-dropdown) > .nav-link').forEach(function (a) {
a.addEventListener('click', function () {
if (isMobile()) closeMenu();
});
});

// Клик мимо открытого dropdown на десктопе — закрыть его
document.addEventListener('click', function (e) {
if (!isMobile()) {
document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function (li) {
if (!li.contains(e.target)) {
li.classList.remove('open');
var t = li.querySelector('.nav-toggle');
if (t) t.setAttribute('aria-expanded', 'false');
}
});
}
});
})();

