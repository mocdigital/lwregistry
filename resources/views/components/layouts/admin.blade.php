<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Loveworld Registry – Admin Dashboard' }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />

    <!-- Bootstrap 5.3.3 - SAME AS MAIN APP -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Admin CSS -->
    <link href="{{ asset('css/admin.css?v=1.1') }}" rel="stylesheet" />
    <link href="{{ asset('css/form-help.css?v=3') }}" rel="stylesheet" />
    <link href="{{ asset('css/notification-bell.css?v=2') }}" rel="stylesheet" />
    <link href="{{ asset('css/ui-foundation.css?v=3') }}" rel="stylesheet" />
    <link href="{{ asset('css/admin-workspace.css?v=1') }}" rel="stylesheet" />

    {{--
        The same two families the member site loads, so the admin is not a
        third type system. Preconnected and swapped so the text is never
        invisible while they arrive.
    --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap"
    >

    {{--
        Last, so it wins over admin.css and Bootstrap without needing
        !important. See the file header for why the admin is themed at the
        primitive level rather than rewritten page by page.
    --}}
    <link href="{{ asset('css/admin-theme.css?v=3') }}" rel="stylesheet" />
    @stack('styles')
    @livewireStyles
</head>
<body data-page="{{ $dataPage ?? 'dashboard' }}">

    @include('admin.partials.sidebar')

    <div class="main-content">
        @include('admin.partials.top-nav', ['pageTitle' => $pageTitle ?? 'Admin Dashboard'])

        <main class="admin-main p-3 p-md-4 p-xl-5">
            {{ $slot }}
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/form-help.js?v=4') }}"></script>
    <script src="{{ asset('js/ui-feedback.js?v=1') }}"></script>

    <!-- Sidebar toggle script -->
    <script>
        function initializeAdminSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            const closeBtn = document.querySelector('[data-admin-sidebar-close]');
            const backdrop = document.querySelector('[data-admin-sidebar-backdrop]');

            if (!sidebar || !toggleBtn || sidebar.dataset.mobileSidebarReady === 'true') {
                return;
            }

            sidebar.dataset.mobileSidebarReady = 'true';

            const toggleIcon = toggleBtn.querySelector('.admin-sidebar-toggle-icon');
            const toggleLabel = toggleBtn.querySelector('[data-admin-sidebar-toggle-label]');
            const isMobile = () => window.innerWidth < 992;

            function setSidebarState(isOpen) {
                sidebar.classList.toggle('show', isOpen);
                document.body.classList.toggle('admin-sidebar-open', isOpen);
                toggleBtn.classList.toggle('is-open', isOpen);
                toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', isOpen ? 'Close sidebar menu' : 'Open sidebar menu');

                if (toggleIcon) {
                    toggleIcon.classList.toggle('bi-list', !isOpen);
                    toggleIcon.classList.toggle('bi-x-lg', isOpen);
                }

                if (toggleLabel) {
                    toggleLabel.textContent = isOpen ? 'Close' : 'Menu';
                }

                if (backdrop) {
                    backdrop.hidden = !isOpen;
                    backdrop.classList.toggle('show', isOpen);
                }
            }

            function openSidebar() {
                if (isMobile()) {
                    setSidebarState(true);
                }
            }

            function closeSidebar() {
                setSidebarState(false);
            }

            toggleBtn.addEventListener('click', function () {
                if (sidebar.classList.contains('show')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });

            closeBtn?.addEventListener('click', closeSidebar);
            backdrop?.addEventListener('click', closeSidebar);

            sidebar.querySelectorAll('.nav-link').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (isMobile()) {
                        closeSidebar();
                    }
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && sidebar.classList.contains('show')) {
                    closeSidebar();
                    toggleBtn.focus();
                }
            });

            window.addEventListener('resize', function () {
                if (!isMobile()) {
                    closeSidebar();
                }
            });

            document.addEventListener('livewire:navigated', closeSidebar);
        }

        document.addEventListener('DOMContentLoaded', initializeAdminSidebar);
        document.addEventListener('livewire:navigated', initializeAdminSidebar);
    </script>

    <!-- Add categoryy modal close -->
    <script>
        window.addEventListener('close-add-category-modal', () => {
            const modal = bootstrap.Modal.getInstance(
                document.getElementById('addCategoryModal')
            );
            modal?.hide();
        });
    </script>
    
    <!-- Rename categoryy modal close -->
    <script>
        window.addEventListener('open-rename-category-modal', () => {
            new bootstrap.Modal(
                document.getElementById('renameCategoryModal')
            ).show();
        });

        window.addEventListener('close-rename-category-modal', () => {
            const modal = bootstrap.Modal.getInstance(
                document.getElementById('renameCategoryModal')
            );
            modal?.hide();
        });
    </script>

    <!-- Ministry modals -->
    <script>
        window.addEventListener('close-add-ministry-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('addMinistryModal'))?.hide();
        });

        window.addEventListener('open-rename-ministry-modal', () => {
            new bootstrap.Modal(document.getElementById('renameMinistryModal')).show();
        });

        window.addEventListener('close-rename-ministry-modal', () => {
            bootstrap.Modal.getInstance(document.getElementById('renameMinistryModal'))?.hide();
        });
    </script>

    <!-- Add location modal close -->
    <script>
        window.addEventListener('close-add-location-modal', () => {
            const modalEl = document.getElementById('addLocationModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal?.hide();
        });
    </script>

    <!-- View user modal open -->
    <script>
        window.addEventListener('close-add-user-modal', () => {
            const modalEl = document.getElementById('addUserModal');
            const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            modal?.hide();
        });
        window.addEventListener('open-user-modal', () => {
            new bootstrap.Modal(
                document.getElementById('userDetailsModal')
            ).show();
        });
        window.addEventListener('close-user-modal', () => {
            const modalEl = document.getElementById('userDetailsModal');
            const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            modal?.hide();
        });
    </script>

    <!-- Create org modal close -->
    <script>
        window.addEventListener('close-create-org-modal', () => {
            const modalEl = document.getElementById('createOrgModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal?.hide();
        });
    </script>

    <!-- Edit org modal open -->
    <script>
    window.addEventListener('open-edit-org-modal', () => {
        new bootstrap.Modal(document.getElementById('editOrgModal')).show();
    });

    window.addEventListener('close-edit-org-modal', () => {
        bootstrap.Modal.getInstance(
        document.getElementById('editOrgModal')
        )?.hide();
    });
    </script>

    <!-- Add report modal close -->
    <script>
        window.addEventListener('close-add-report-modal', () => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('addReportModal'));
            modal?.hide();
        });
    </script>

    <!-- Add RFQ modal close -->
    <script>
        window.addEventListener('close-create-rfq-modal', () => {
        const modal = bootstrap.Modal.getInstance(
            document.getElementById('createPOFromRFQModal')
        );
        modal?.hide();
        });
    </script>

    <!-- Listen for file drag and drop during RFQ creation -->
    <script>
        (function () {
        const form = document.getElementById('createRFQForm');
        const dropzone = document.getElementById('rfqDropzone');
        const fileInput = document.getElementById('rfqFileInput');
        const fileNameEl = document.getElementById('rfqFileName');
        const browseBtn = document.getElementById('rfqBrowseBtn');

        if (!dropzone || !fileInput) return;

        // Click → open file picker
        dropzone.addEventListener('click', () => fileInput.click());
        if (browseBtn) {
            browseBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
            });
        }

        // When file is selected via dialog
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) {
            fileNameEl.style.display = 'none';
            fileNameEl.textContent = '';
            return;
            }
            fileNameEl.textContent = file.name;
            fileNameEl.style.display = 'block';
        });

        // Drag & drop
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('rfq-dropzone--active');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('rfq-dropzone--active');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (!dt || !dt.files || !dt.files.length) return;

            const file = dt.files[0];

            // Create a new DataTransfer so Livewire detects it
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;

            // Trigger change event so Livewire picks it up
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));

            fileNameEl.textContent = file.name;
            fileNameEl.style.display = 'block';
        });

        })();
    </script>

    <!-- Create Admin modal Close -->
    <script>
        window.addEventListener('close-add-admin-modal', () => {
            const modal = bootstrap.Modal.getInstance(
                document.getElementById('addAdminModal')
            );
            modal?.hide();
        });
    </script>
    @include('partials.echo')
    @include('partials.livewire-scripts')
    @stack('scripts')
</body>
</html>
