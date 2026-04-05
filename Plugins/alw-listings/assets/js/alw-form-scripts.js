// wp-content/plugins/alw-listings/assets/js/alw-form-scripts.js
document.addEventListener('DOMContentLoaded', function() {
    // Password visibility toggle
    const pwordElements = document.querySelectorAll('.password');
    pwordElements.forEach(pwordElement => {
        let addon = pwordElement.nextElementSibling; // Assuming addon is next sibling
        if (addon && addon.classList.contains('input-add-on')) {
            addon.style.cursor = 'pointer'; // Ensure cursor indicates interactivity
            addon.onclick = () => {
                const icon = addon.querySelector('i');
                if (pwordElement.getAttribute('type') === 'password') {
                    pwordElement.setAttribute('type', 'text');
                    if (icon) icon.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    pwordElement.setAttribute('type', 'password');
                    if (icon) icon.classList.replace('fa-eye-slash', 'fa-eye');
                }
            };
        }
    });

    // No ReCAPTCHA or complex client-side validation for this basic version.
    // Form will submit traditionally to admin-post.php.
});