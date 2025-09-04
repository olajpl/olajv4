<?php /* Toggle rozwijania grup oraz formularza "Dodaj pozycję" */ ?>
<script>
    document.addEventListener('click', function(e) {
        // toggle grupy
        const btn = e.target.closest('[data-toggle]');
        if (btn) {
            const id = btn.getAttribute('data-toggle');
            const box = document.getElementById(id);
            if (box) {
                const hidden = box.classList.contains('hidden');
                box.classList.toggle('hidden');
                const ico = document.querySelector('[data-icon-for="' + id + '"]');
                if (ico) ico.style.transform = hidden ? 'rotate(180deg)' : 'rotate(0deg)';
            }
        }
        // toggle "Dodaj pozycję"
        const addBtn = e.target.closest('[data-add-toggle]');
        if (addBtn) {
            const id = addBtn.getAttribute('data-add-toggle');
            const box = document.getElementById(id);
            if (box) box.classList.toggle('hidden');
        }
    });
</script>