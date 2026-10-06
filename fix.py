import re

def fix_file(filename):
    with open(filename, "r") as f:
        content = f.read()

    # Fix JS
    js_correct = """<script>
    document.addEventListener('DOMContentLoaded', () => {
        const filters = document.querySelectorAll('.filter-btn');
        const slots = document.querySelectorAll('.slot-item');
        if (!filters.length) return;
        filters.forEach(btn => {
            btn.addEventListener('click', () => {
                filters.forEach(f => {
                    f.classList.remove('bg-gray-900', 'text-white');
                    f.classList.add('bg-gray-100', 'text-gray-700');
                });
                btn.classList.remove('bg-gray-100', 'text-gray-700');
                btn.classList.add('bg-gray-900', 'text-white');
                const filter = btn.dataset.filter;
                slots.forEach(slot => {
                    if (filter === 'all' || slot.dataset.summary === filter) {
                        slot.style.display = '';
                    } else {
                        slot.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>"""
    content = re.sub(r'<script>.*?</script>', js_correct, content, flags=re.DOTALL)
    
    with open(filename, "w") as f:
        f.write(content)

fix_file("index.php")
fix_file("i.php")
