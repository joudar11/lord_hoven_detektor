// Nastaví světlý / tmavý motiv ještě před vykreslením stránky (bez probliknutí)
(function() {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
})();
    
