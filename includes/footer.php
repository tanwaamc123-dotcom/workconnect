</main>
<footer class="site-footer" id="contact">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a class="brand" href="?page=home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span><span>WorkConnect</span></a>
            <p>Connect talent.<br>Create success.</p>
        </div>
        <div><strong>Explore</strong><a href="?page=home">Home</a><a href="?page=services">Services</a><a href="?page=home#how-it-works">How it works</a></div>
        <div><strong>Company</strong><a href="?page=about">About</a><a href="<?= e(contact_ig_setting()) ?>" target="_blank" rel="noopener">Contact</a><a href="?page=privacy">Privacy</a></div>
        <div><strong>Support</strong><a href="?page=help-center">Help center</a><a href="?page=safety">Safety</a><a href="?page=community">Community</a></div>
        <form class="newsletter" action="" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscribe">
            <strong>Stay updated</strong><p>Useful updates, never inbox noise.</p>
            <label class="sr-only" for="newsletter-email">Email address</label>
            <div><input id="newsletter-email" name="email" type="email" placeholder="Enter your email" required><button class="button button-dark button-small" type="submit">Subscribe</button></div>
        </form>
    </div>
    <div class="container footer-bottom"><span>© 2026 WorkConnect. All rights reserved.</span><span>Student project made in Thailand</span></div>
</footer>
<script src="assets/js/app.js"></script>
</body>
</html>
