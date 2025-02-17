document.addEventListener('DOMContentLoaded', function() {
    const previewToggle = document.querySelector('.preview-toggle');
    const articleContent = document.querySelector('.article-content');
    const paywallOverlay = document.querySelector('.paywall-overlay');
    
    if (previewToggle && articleContent && paywallOverlay) {
        previewToggle.addEventListener('click', function() {
            articleContent.classList.toggle('full');
            paywallOverlay.style.display = articleContent.classList.contains('full') ? 'none' : 'flex';
            previewToggle.textContent = articleContent.classList.contains('full') ? 
                'Show Preview' : 'Show Full Content';
        });
    }
});
