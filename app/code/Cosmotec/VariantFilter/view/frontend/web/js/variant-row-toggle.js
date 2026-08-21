define([], function () {
    'use strict';

    return function bindRowToggle(container) {

        container.querySelectorAll('.product-info-link').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();

                const clickedRow = this.closest('tr');
                const detailRow = clickedRow?.nextElementSibling;

                if (!clickedRow || !detailRow || !detailRow.classList.contains('product-content-row')) {
                    return;
                }

                if (clickedRow.classList.contains('current')) {
                    window.location.href = this.href;
                    return;
                }

                const isAlreadyOpen = detailRow.classList.contains('open');

                // Reset all rows
                container.querySelectorAll('tr').forEach(row => {
                    row.classList.remove('open', 'hidden-row', 'current');
                });

                // If already open → restore table
                if (isAlreadyOpen) {
                    container.querySelector('.area-loader-back-button').style.display = 'none';
                    container.querySelector('.product-return-list-btn').style.display = 'none';
                    return;
                }
                container.querySelector('.area-loader-back-button').style.display = 'block';
                container.querySelector('.product-return-list-btn').style.display = 'block';


                // Hide all rows except table heading
                container.querySelectorAll('tr').forEach(row => {
                    if (!row.classList.contains('table-heading-row')) {
                        row.classList.add('hidden-row');
                    }
                });

                // Show clicked row + its detail row
                clickedRow.classList.remove('hidden-row');
                clickedRow.classList.add('current');
                detailRow.classList.remove('hidden-row');
                detailRow.classList.add('open');
            });
        });
    };
});
