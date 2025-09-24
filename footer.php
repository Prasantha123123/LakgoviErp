</div>
    
    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-auto">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="text-center text-sm text-gray-600">
                © 2025 Factory ERP System - by JAAN Network Pvt Ltd
            </div>
        </div>
    </footer>

    <!-- Common JavaScript Functions -->
    <script>
        // Close modal function
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Open modal function
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        // Confirm delete function
        function confirmDelete(message = 'Are you sure you want to delete this item?') {
            return confirm(message);
        }

        // Format number
        function formatNumber(num, decimals = 2) {
            return parseFloat(num).toFixed(decimals);
        }

        // Auto-calculate amount
        function calculateAmount(qtyId, rateId, amountId) {
            const qty = parseFloat(document.getElementById(qtyId).value) || 0;
            const rate = parseFloat(document.getElementById(rateId).value) || 0;
            document.getElementById(amountId).value = formatNumber(qty * rate);
        }

        // Show success message
        function showMessage(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `fixed top-4 right-4 px-4 py-3 rounded-md shadow-lg z-50 ${
                type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
            }`;
            alertDiv.textContent = message;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                document.body.removeChild(alertDiv);
            }, 3000);
        }

        // Handle form submission with loading state
        function handleFormSubmit(formId, buttonId) {
            const form = document.getElementById(formId);
            const button = document.getElementById(buttonId);
            
            form.addEventListener('submit', function() {
                button.disabled = true;
                button.textContent = 'Processing...';
                button.className = button.className.replace('bg-primary', 'bg-gray-400');
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal-backdrop')) {
                    const modal = e.target;
                    modal.classList.add('hidden');
                }
            });

            // Handle escape key to close modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modals = document.querySelectorAll('[id$="Modal"]:not(.hidden)');
                    modals.forEach(modal => modal.classList.add('hidden'));
                }
            });
        });
    </script>
</body>
</html>