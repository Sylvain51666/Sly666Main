// =====================================================
// APP.JS - CheckList DPS avec mode Vue Large
// VERSION 10.2 (Ajout Expiration Brouillon)
// - Corrigé: restoreDraft() fusionne l'état au lieu de l'écraser.
// - AJOUT: Vérification de l'âge du brouillon avant restauration.
// =====================================================

// ===== CONFIGURATION =====
const DEFAULT_CONFIG = {
    // Durées
    autoSaveInterval: 30000,
    toastDuration: 1500,
    toastValidationDuration: 5000,
    toastSuccessDuration: 8000,
    // Autres
    maxCommentLength: 1000,
    storageKey: 'checklistDPS_draft',
    draftMaxAgeMinutes: 30, // Expiration du brouillon en minutes (AJOUT)
    // Textes... (inchangés)
    text_themeChangedTitle: "Thème changé",
    text_themeChangedMessage: "Mode {themeName} activé",
    text_viewModeChangedTitle: "Mode d'affichage",
    text_viewModeChangedMessageLarge: "Vue Large activée",
    text_viewModeChangedMessageNormal: "Vue Standard activée",
    text_validationErrorTitle: "Validation impossible",
    text_validationErrorMessage: "Toute la liste doit être vérifiée.",
    text_validationWarningTitle: "Champs manquants",
    text_validationWarningMessage: "Les éléments suivants ne sont pas vérifiés :\n{items}",
    text_successTitle: "Succès",
    text_successMessagePrefix: "Checklist validée avec succès !",
    text_successNetworkErrorTitle: "Validation terminée",
    text_successNetworkErrorMessage: "Checklist validée LOCALEMENT.\nL'envoi au serveur a échoué.",
    text_issuesDetected: "Problèmes détectés:",
    text_issuesMissing: "Manquants:",
    text_issuesFailing: "Défaillants:",
    text_resetTitle: "Formulaire réinitialisé",
    text_resetMessage: "Vous pouvez effectuer une nouvelle vérification.",
    text_draftRestoredTitle: "Brouillon restauré",
    text_draftRestoredMessage: "Votre dernière saisie a été récupérée.",
    text_resetModalTitle: "Réinitialiser ?",
    text_resetModalMessage: "Voulez-vous réinitialiser le formulaire pour une nouvelle vérification ?",
    text_resetModalConfirmButton: "Oui, réinitialiser",
    text_resetModalCancelButton: "Non, merci",
    text_welcomeTitle: "🚒 Bienvenue sur la Check-List DPS",
    text_welcomeMessage: "Cette liste doit être remplie avant chaque départ de DPS.<br>Validez-la en cliquant sur le bouton \"Envoyer\"."
};

const CONFIG = {
    ...DEFAULT_CONFIG,
    ...(window.DYNAMIC_CONFIG || {})
};

// Override draft max age if defined in DYNAMIC_CONFIG
// Needs a specific key like 'app_draftMaxAgeMinutes' in the settings table
if (window.DYNAMIC_CONFIG && window.DYNAMIC_CONFIG.draftMaxAgeMinutes !== undefined) {
    CONFIG.draftMaxAgeMinutes = parseInt(window.DYNAMIC_CONFIG.draftMaxAgeMinutes, 10) || 30;
}


// ===== STATE MANAGEMENT =====
let checklistState = {
    products: {},
    commentaire: '',
    lastSaved: null
};

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 CheckList DPS - Initialisation (Logique V10.2)');
    console.log('Config chargée:', CONFIG);

    // --- Logique Globale (pour index.php ET dps.php) ---
    initThemeToggle();
    initViewModeToggle();
    initWelcomePopup(); // Gère les popups (index.php et dps.php)
    initModal(); // Gère le modal image (sur dps.php, sans risque sur index.php)

    // --- Logique Spécifique (UNIQUEMENT pour dps.php) ---
    // On vérifie la présence d'un élément clé de la checklist, comme le bouton d'envoi
    const sendButton = document.getElementById('send-button');
    
    if (sendButton) {
        console.log('Mode Checklist (dps.php) détecté. Initialisation du formulaire.');
        initProducts(); 
        initCommentaire();
        initSendButton(); // Le listener sera ajouté ici
        initAutoSave();
        initResetModal();
        restoreDraft(); // NE SERA EXÉCUTÉ QUE SUR dps.php
        updateModalStaticTexts();
    } else {
        console.log('Mode Sélection (index.php) détecté. Initialisation du formulaire ignorée.');
    }

    console.log('✅ Initialisation terminée');
});

// ... (initThemeToggle, updateThemeIcon, initViewModeToggle, updateViewIcon, initModal, initResetModal, updateModalStaticTexts restent INCHANGÉS) ...
// Copiez ces fonctions depuis le fichier V10.1 que je vous ai donné précédemment

// ===== THEME TOGGLE (DARK MODE) =====
function initThemeToggle() {
    const toggleButton = document.createElement('button');
    toggleButton.className = 'theme-toggle';
    toggleButton.innerHTML = '<span class="theme-icon">☀️</span>';
    toggleButton.setAttribute('aria-label', 'Changer de thème');
    document.body.appendChild(toggleButton);

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    let currentTheme = savedTheme || (prefersDark ? 'dark' : 'light'); // Use saved or system preference

    document.body.className = currentTheme + '-mode';
    updateThemeIcon(currentTheme);

    toggleButton.addEventListener('click', function() {
        currentTheme = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        // Maintenir le mode vue si actif
        const isLargeView = document.body.classList.contains('large-view');
        document.body.className = newTheme + '-mode'; // Set base class first
        if (isLargeView) document.body.classList.add('large-view'); // Re-add large-view if needed

        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);

        // Utilisation des textes de CONFIG
        const themeName = newTheme === 'dark' ? 'sombre' : 'clair';
        const message = CONFIG.text_themeChangedMessage.replace('{themeName}', themeName);
        showToast(CONFIG.text_themeChangedTitle, message, 'info');
    });
}

function updateThemeIcon(theme) {
    const icon = document.querySelector('.theme-icon');
    if (icon) {
        icon.textContent = theme === 'dark' ? '☀️' : '🌙';
    }
}

// ===== VIEW MODE TOGGLE (LARGE VIEW) =====
function initViewModeToggle() {
    const viewToggleButton = document.createElement('button');
    viewToggleButton.className = 'view-toggle';
    viewToggleButton.innerHTML = '<span class="view-icon">👆</span>';
    viewToggleButton.setAttribute('aria-label', 'Mode vue large');
    document.body.appendChild(viewToggleButton);

    const savedViewMode = localStorage.getItem('viewMode');
    if (savedViewMode === 'large') {
        document.body.classList.add('large-view');
        updateViewIcon(true);
    } else {
         updateViewIcon(false); // Make sure icon is correct initially
    }

    viewToggleButton.addEventListener('click', function() {
        const isLargeView = document.body.classList.toggle('large-view');
        localStorage.setItem('viewMode', isLargeView ? 'large' : 'normal');
        updateViewIcon(isLargeView);

        // Utilisation des textes de CONFIG
        const message = isLargeView ? CONFIG.text_viewModeChangedMessageLarge : CONFIG.text_viewModeChangedMessageNormal;
        showToast(CONFIG.text_viewModeChangedTitle, message, 'info');
    });
}

function updateViewIcon(isLargeView) {
    const icon = document.querySelector('.view-icon');
    if (icon) {
        icon.textContent = isLargeView ? '👁️' : '👆';
    }
}

// ===== MODAL IMAGE =====
function initModal() {
    const modal = document.getElementById('image-modal');
    const modalImage = modal ? modal.querySelector('img') : null;

    if (!modal || !modalImage) {
        // console.warn("Modal d'image (#image-modal ou son img) introuvable.");
        // Pas un warning, car c'est normal sur index.php
        return;
    }

    document.addEventListener('click', function(e) {
        if (e.target.matches('.product img')) {
            const highResSrcAttr = e.target.getAttribute('data-high-res');
            if (highResSrcAttr) {
                 // Si c'est juste le nom de fichier, ajouter img/
                 let imageSource = highResSrcAttr;
                 if (!highResSrcAttr.includes('/') && highResSrcAttr !== 'noimage_high.jpg') {
                     imageSource = 'img/' + highResSrcAttr;
                 } else if (highResSrcAttr === 'noimage_high.jpg') {
                     imageSource = 'img/noimage_high.jpg'; // Path complet pour l'image par défaut
                 }
                modalImage.src = imageSource;
                modal.classList.add('show');
            }
        }
    });

    modal.addEventListener('click', function() {
        modal.classList.remove('show');
        setTimeout(() => { modalImage.src = ''; }, 300); // Clear src after fade out
    });
}

// ===== MODAL DE RESET =====
function initResetModal() {
    const modal = document.getElementById('reset-modal');
    const confirmBtn = document.getElementById('reset-confirm-btn');
    const cancelBtn = document.getElementById('reset-cancel-btn');

    if (!modal || !confirmBtn || !cancelBtn) {
        console.error('Éléments du modal de réinitialisation introuvables.');
        return;
    }

    confirmBtn.addEventListener('click', () => {
        resetForm();
        modal.classList.remove('show');
    });

    cancelBtn.addEventListener('click', () => {
        modal.classList.remove('show');
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) { // Click on overlay only
            modal.classList.remove('show');
        }
    });
}

// Met à jour les textes du modal qui viennent de CONFIG
function updateModalStaticTexts() {
     const titleElement = document.getElementById('reset-modal-title');
     const messageElement = document.getElementById('reset-modal-message');
     const confirmBtn = document.getElementById('reset-confirm-btn');
     const cancelBtn = document.getElementById('reset-cancel-btn');

     if (titleElement) titleElement.textContent = CONFIG.text_resetModalTitle;
     if (messageElement) messageElement.textContent = CONFIG.text_resetModalMessage;
     if (confirmBtn) confirmBtn.textContent = CONFIG.text_resetModalConfirmButton;
     if (cancelBtn) cancelBtn.textContent = CONFIG.text_resetModalCancelButton;
}


// ===== PRODUCTS MANAGEMENT =====
function initProducts() {
    const products = document.querySelectorAll('.product');

    products.forEach(product => {
        const productId = product.getAttribute('data-product-id');
        const select = product.querySelector('select');
        const checkbox = product.querySelector('input[type="checkbox"]');

        if (!select || !checkbox) {
            console.warn(`Contrôles manquants pour produit ID ${productId}`);
            return; // Skip this product if controls are missing
        }

        createStatusButtons(product, select);

        // Popule l'état global avec le nom
        checklistState.products[productId] = {
            nom: product.querySelector('.product-info h3')?.textContent || 'Nom inconnu',
            etat: select.value,
            ok: checkbox.checked
        };

        updateProductState(select, checkbox);

        select.addEventListener('change', function() {
            if (select.value !== 'vide') checkbox.checked = false;
            updateProductState(select, checkbox);

            updateButtonsState(product, select.value);
            // Met à jour l'état global SANS perdre le nom
            checklistState.products[productId].etat = select.value;
            checklistState.products[productId].ok = checkbox.checked;
            saveDraft();
            if (document.body.classList.contains('large-view')) scrollToNextProduct(product);
            product.style.transform = 'scale(0.98)';
            setTimeout(() => { product.style.transform = ''; }, 150);
        });

        checkbox.addEventListener('change', function() {
            if (checkbox.checked) select.value = 'vide';
            updateProductState(select, checkbox);
            updateButtonsState(product, select.value);

            // Met à jour l'état global SANS perdre le nom
            checklistState.products[productId].etat = select.value;
            checklistState.products[productId].ok = checkbox.checked;
            saveDraft();
            if (checkbox.checked && document.body.classList.contains('large-view')) scrollToNextProduct(product);
            if (checkbox.checked) {
                product.style.transform = 'scale(1.02)';
                setTimeout(() => { product.style.transform = ''; }, 150);
            }
        });
    });
}

// ... (createStatusButtons, updateButtonsState, scrollToNextProduct, updateProductState restent INCHANGÉS) ...
// Copiez ces fonctions depuis le fichier V10.1 que je vous ai donné précédemment

// Créer les boutons de statut pour le mode large
function createStatusButtons(product, select) {
    const controlsContainer = product.querySelector('.product-controls');
    if (!controlsContainer) return;

    // Remove existing buttons if re-initializing (safety)
    const existingButtons = controlsContainer.querySelector('.status-buttons');
    if (existingButtons) existingButtons.remove();

    const buttonsContainer = document.createElement('div');
    buttonsContainer.className = 'status-buttons';

    const statuses = [
        { value: 'vide', label: 'OK', icon: '✅' },
        { value: 'manquant', label: 'Manquant', icon: '❌' },
        { value: 'defaillant', label: 'Défaillant', icon: '⚠️' }
    ];

    statuses.forEach(status => {
        const btn = document.createElement('button');
        btn.className = 'status-btn';
        btn.setAttribute('data-status', status.value);
        btn.innerHTML = `<span class="status-icon">${status.icon}</span><span class="status-label">${status.label}</span>`;
        btn.type = 'button'; // Important for forms

        btn.addEventListener('click', function() {
            // Update the underlying select, which triggers its change event
            select.value = status.value;
            select.dispatchEvent(new Event('change'));
        });

        buttonsContainer.appendChild(btn);
    });

    controlsContainer.appendChild(buttonsContainer);
    updateButtonsState(product, select.value); // Set initial active state
}

// Mettre à jour l'état visuel des boutons
function updateButtonsState(product, currentValue) {
    const buttons = product.querySelectorAll('.status-btn');
    buttons.forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-status') === currentValue);
    });
}

// Scroll vers le produit suivant ou le footer
function scrollToNextProduct(currentProduct) {
    const allProducts = Array.from(document.querySelectorAll('.product'));
    const currentIndex = allProducts.indexOf(currentProduct);

    let targetElement;
    let scrollOptions = { behavior: 'smooth', block: 'center' };

    if (currentIndex < allProducts.length - 1) {
        // Not the last product, scroll to the next one
        targetElement = allProducts[currentIndex + 1];
    } else {
        // Last product, scroll to the footer
        targetElement = document.querySelector('.footer');
        scrollOptions.block = 'end'; // Align footer to bottom
    }

    if (targetElement) {
         setTimeout(() => {
            targetElement.scrollIntoView(scrollOptions);
        }, 300); // Small delay after action
    }
}

// Logique de désactivation (grisé)
function updateProductState(select, checkbox) {
    const product = select.closest('.product');
    if (!product) return;

    const statusButtons = product.querySelectorAll('.status-btn[data-status="manquant"], .status-btn[data-status="defaillant"]');

    if (checkbox.checked) {
        // Case 1: "Vérifié" is checked -> disable select and non-OK buttons
        select.disabled = true;
        statusButtons.forEach(btn => btn.disabled = true);
        checkbox.disabled = false; // Ensure checkbox remains enabled
    } else {
        // Case 2: "Vérifié" is not checked -> enable everything
        select.disabled = false;
        checkbox.disabled = false;
        statusButtons.forEach(btn => btn.disabled = false);
    }
}


// ===== COMMENTAIRE =====
function initCommentaire() {
    const commentaire = document.getElementById('commentaire');
    if (!commentaire) return;

    const charCountContainer = document.createElement('div');
    charCountContainer.id = 'charCount';

    const wrapper = document.createElement('div');
    wrapper.className = 'commentaire-wrapper';
    commentaire.parentNode?.insertBefore(wrapper, commentaire); // Use optional chaining for safety
    wrapper.appendChild(commentaire);
    wrapper.appendChild(charCountContainer);

    function updateCharCount() {
        const remaining = CONFIG.maxCommentLength - commentaire.value.length;
        charCountContainer.textContent = `${remaining} caractères restants`;
        charCountContainer.style.color = remaining < 100 ? 'var(--color-warning)' : 'var(--text-secondary)';
    }

    updateCharCount(); // Initial count

    commentaire.addEventListener('input', function() {
        if (commentaire.value.length > CONFIG.maxCommentLength) {
            commentaire.value = commentaire.value.slice(0, CONFIG.maxCommentLength);
        }
        updateCharCount();
        checklistState.commentaire = commentaire.value;
        saveDraft(); // Save on input
    });
}

// ===== SEND BUTTON =====
function initSendButton() {
    const sendButton = document.getElementById('send-button');
    if (sendButton) {
        sendButton.addEventListener('click', validateAndSendChecklist);
    }
}

// ... (validateAndSendChecklist, sendChecklistToServer, resetForm restent INCHANGÉS) ...
// Copiez ces fonctions depuis le fichier V10.1 que je vous ai donné précédemment

function validateAndSendChecklist() {
    document.querySelectorAll('.product.incomplete').forEach(p => p.classList.remove('incomplete'));

    const products = document.querySelectorAll('.product');
    const incompleteProducts = [];
    const missingProducts = [];
    const defaillantProducts = [];

    products.forEach(product => {
        const nom = product.querySelector('.product-info h3')?.textContent || 'Inconnu';
        const select = product.querySelector('select');
        const checkbox = product.querySelector('input[type="checkbox"]');

        // Ensure controls exist before proceeding
        if (!select || !checkbox) return;

        // Logique V10: Est incomplet si non coché ET sur "OK"
        if (!checkbox.checked && select.value === 'vide') {
            incompleteProducts.push(nom);
            product.classList.add('incomplete');
        }

        if (select.value === 'manquant') missingProducts.push(nom);
        else if (select.value === 'defaillant') defaillantProducts.push(nom);
    });

    // Utilisation des textes et durées de CONFIG
    if (incompleteProducts.length >= 3) {
        showToast(CONFIG.text_validationErrorTitle, CONFIG.text_validationErrorMessage, 'error', CONFIG.toastValidationDuration);
        return;
    }
    if (incompleteProducts.length > 0) {
        const message = CONFIG.text_validationWarningMessage.replace('{items}', incompleteProducts.join(', '));
        showToast(CONFIG.text_validationWarningTitle, message, 'warning', CONFIG.toastValidationDuration);
        return;
    }

    sendChecklistToServer(missingProducts, defaillantProducts);
}

// Envoi au serveur (Version 6: Gestion erreur optimiste + Modal custom)
function sendChecklistToServer(missingProducts, defaillantProducts) {
    const sendButton = document.getElementById('send-button');
    if (!sendButton) return;
    const originalText = sendButton.innerHTML;

    sendButton.disabled = true;
    sendButton.innerHTML = '<span class="loading"></span> Envoi en cours...';

    // On envoie l'état global qui contient les noms
    const data = {
        products: checklistState.products,
        commentaire: checklistState.commentaire,
        date: new Date().toISOString()
    };

    // Fonction interne pour gérer la fin (succès ou échec réseau)
    const handleValidation = (result) => {
        const now = new Date();
        const dateStr = now.toLocaleDateString('fr-FR');
        const timeStr = now.toLocaleTimeString('fr-FR');

        let message;
        let toastType;
        let toastTitle = CONFIG.text_successNetworkErrorTitle; // Default title (network error)

        if (result && result.success) {
            toastTitle = CONFIG.text_successTitle; // Success title
            message = `${CONFIG.text_successMessagePrefix}\n📅 ${dateStr} à ${timeStr}\n🆔 Référence: ${result.checklist_id}`;
            toastType = 'success';
        } else {
            message = `${CONFIG.text_successNetworkErrorMessage}\n📅 ${dateStr} à ${timeStr}`;
            toastType = 'warning';
        }

        if (missingProducts.length > 0 || defaillantProducts.length > 0) {
            message += `\n\n⚠️ ${CONFIG.text_issuesDetected}`;
            if (missingProducts.length > 0) message += `\n❌ ${CONFIG.text_issuesMissing} ${missingProducts.join(', ')}`;
            if (defaillantProducts.length > 0) message += `\n🔧 ${CONFIG.text_issuesFailing} ${defaillantProducts.join(', ')}`;
        }

        showToast(toastTitle, message, toastType, CONFIG.toastSuccessDuration); // Use config duration

        sendButton.disabled = false; // Re-enable button
        sendButton.innerHTML = originalText;
        clearDraft(); // Clear draft regardless of server success

        // Show the custom reset modal after a delay
        setTimeout(() => {
            document.getElementById('reset-modal')?.classList.add('show');
        }, 2000);
    };

    // Appel Fetch
    fetch('save_checklist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => {
        if (!response.ok) throw new Error(`Erreur HTTP: ${response.status}`);
        return response.json();
    })
    .then(result => {
        if (result.success) handleValidation(result); // Server reported success
        else { // Server reported failure (e.g., {"success": false, "message": "..."})
            console.error('Erreur rapportée par le serveur (validation optimiste):', result.message);
            handleValidation(null); // Proceed with client-side validation anyway
        }
    })
    .catch(error => { // Network error or invalid JSON etc.
        console.error('Erreur réseau ou fetch (validation optimiste):', error);
        handleValidation(null); // Proceed with client-side validation anyway
    });
}


function resetForm() {
    document.querySelectorAll('.product select').forEach(select => {
        select.value = 'vide';
        select.disabled = false;
    });
    document.querySelectorAll('.product input[type="checkbox"]').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.disabled = false;
    });
    const commentaireInput = document.getElementById('commentaire');
    if (commentaireInput) commentaireInput.value = '';

    // Réinitialise l'état global
    Object.keys(checklistState.products).forEach(productId => {
        checklistState.products[productId].etat = 'vide';
        checklistState.products[productId].ok = false;
    });
    checklistState.commentaire = '';
    checklistState.lastSaved = null;

    // Reset visual state (colors and disabled status)
    document.querySelectorAll('.product').forEach(product => {
        const select = product.querySelector('select');
        const checkbox = product.querySelector('input[type="checkbox"]');
        if (select && checkbox) {
            updateProductState(select, checkbox); // Update disabled state
            updateButtonsState(product, 'vide'); // Update button colors
        }
    });

    showToast(CONFIG.text_resetTitle, CONFIG.text_resetMessage, 'info'); // Use config text
}

// ===== WELCOME POPUP =====
function initWelcomePopup() {
    const popup = document.getElementById('popup');
    const titleElement = popup ? popup.querySelector('h1') : null;
    const messageElement = popup ? popup.querySelector('p') : null;

    if (popup && titleElement && messageElement) {
        
        // --- MODIFIÉ ---
        // S'assurer que le popup a le contenu de CONFIG
        // (car index.php et dps.php ont des textes différents)
        const configTitle = CONFIG.text_welcomeTitle;
        const configMessage = CONFIG.text_welcomeMessage;

        // Si les textes PHP (via DYNAMIC_CONFIG) existent, ils écrasent les défauts.
        // index.php a ses propres textes dans $settings
        // dps.php a aussi les siens.
        // Cette fonction utilise CONFIG, qui est déjà fusionné avec DYNAMIC_CONFIG.
        
        // Mettre à jour le contenu (au cas où il viendrait de DYNAMIC_CONFIG)
        titleElement.innerHTML = configTitle;
        messageElement.innerHTML = configMessage;

        // Afficher le popup (Normalement déjà fait par HTML/CSS sur index.php)
        // Mais s'il est caché par défaut (sur dps.php), il faut l'activer.
        // Sauf que... dps.php n'a pas de <div id="popup"> !
        // C'est parfait. initWelcomePopup() ne trouvera #popup que sur index.php.
        if (!popup.classList.contains('show')) popup.classList.add('show');

        // Fonction pour cacher
        const hidePopup = () => {
            popup.classList.remove('show');
            popup.removeEventListener('click', hidePopup); // Clean up listener
        };

        // Fermer au clic
        popup.addEventListener('click', hidePopup);

        // Auto-fermeture
        const autoCloseDuration = 5000; // 5 secondes
        setTimeout(() => {
            if (popup.classList.contains('show')) hidePopup();
        }, autoCloseDuration);
    } else {
         // C'est normal sur dps.php, on ne met pas de warning
         // console.warn("Popup de bienvenue (#popup et/ou ses éléments h1/p) introuvable.");
    }
}


// ===== TOAST NOTIFICATIONS =====
function showToast(title, message, type = 'info', duration = CONFIG.toastDuration) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || icons.info}</div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message.replace(/\n/g, '<br>')}</div>
        </div>
        <button class="toast-close" aria-label="Fermer">×</button>
    `;

    container.appendChild(toast);

    // Trigger fade in animation
    setTimeout(() => toast.classList.add('show'), 10);

    const closeBtn = toast.querySelector('.toast-close');
    const removeToastFunc = () => {
        toast.classList.add('fade-out');
        // Remove element after fade out animation completes
        setTimeout(() => toast.remove(), 300);
    };

    closeBtn?.addEventListener('click', removeToastFunc); // Close on button click

    // Auto-remove after duration
    setTimeout(removeToastFunc, duration);
}

// ===== DRAFT MANAGEMENT =====
function saveDraft() {
    try {
        const draft = {
            products: checklistState.products,
            commentaire: checklistState.commentaire,
            lastSaved: new Date().toISOString() // Keep track of save time
        };
        localStorage.setItem(CONFIG.storageKey, JSON.stringify(draft));
        // console.log("💾 Brouillon sauvegardé:", new Date(draft.lastSaved));
    } catch (e) {
        console.error('Erreur sauvegarde brouillon:', e);
        showToast('Erreur', 'Impossible de sauvegarder le brouillon.', 'error'); // Inform user
    }
}

// ==========================================================
// FONCTION CORRIGÉE (V10.2 - Avec Expiration)
// ==========================================================
function restoreDraft() {
    try {
        const draftJSON = localStorage.getItem(CONFIG.storageKey);
        if (draftJSON) {
            const draft = JSON.parse(draftJSON);

            // --- AJOUT Vérification Expiration ---
            if (draft.lastSaved && CONFIG.draftMaxAgeMinutes > 0) {
                const savedDate = new Date(draft.lastSaved);
                const now = new Date();
                const ageInMinutes = (now - savedDate) / (1000 * 60);

                if (ageInMinutes > CONFIG.draftMaxAgeMinutes) {
                    console.log(`🗑️ Brouillon expiré (plus de ${CONFIG.draftMaxAgeMinutes} minutes). Suppression.`);
                    clearDraft();
                    return; // Ne pas restaurer un brouillon expiré
                }
            }
            // --- FIN AJOUT ---

            // NE PAS ÉCRASER L'ÉTAT GLOBAL.
            // initProducts() l'a déjà rempli avec les NOMS.
            // On va le METTRE À JOUR.

            // Restore states from draft
            Object.keys(draft.products || {}).forEach(productId => {
                const product = document.querySelector(`[data-product-id="${productId}"]`);
                if (product) {
                    const select = product.querySelector('select');
                    const checkbox = product.querySelector('input[type="checkbox"]');
                    const productStateFromDraft = draft.products[productId];

                    // Vérifier si l'item existe toujours dans l'état global (chargé depuis le PHP)
                    if (checklistState.products[productId]) {

                        // 1. Mettre à jour l'état global (fusionner)
                        if (productStateFromDraft.etat !== undefined) { // Check existence before assigning
                            checklistState.products[productId].etat = productStateFromDraft.etat;
                        }
                        if (productStateFromDraft.ok !== undefined) {
                            checklistState.products[productId].ok = productStateFromDraft.ok;
                        }

                        // 2. Mettre à jour l'interface (UI)
                        if (select) select.value = checklistState.products[productId].etat;
                        if (checkbox) checkbox.checked = checklistState.products[productId].ok;

                        if(select && checkbox) {
                             updateProductState(select, checkbox); // Mettre à jour l'état grisé
                             updateButtonsState(product, select.value); // Mettre à jour les couleurs des boutons
                        }
                    } else {
                        console.warn(`Produit ID ${productId} trouvé dans le brouillon mais pas dans la page. Ignoré.`);
                    }
                }
            });

            const commentaireInput = document.getElementById('commentaire');
            if (commentaireInput && draft.commentaire !== undefined) { // Check existence
                commentaireInput.value = draft.commentaire;
                // Mettre à jour l'état global
                checklistState.commentaire = draft.commentaire;
                // Déclencher la mise à jour du compteur
                commentaireInput.dispatchEvent(new Event('input'));
            }

            // L'état global (checklistState) est maintenant PROPRE,
            // il contient les noms ET les états/ok du brouillon.

            showToast(CONFIG.text_draftRestoredTitle, CONFIG.text_draftRestoredMessage, 'info'); // Use config text
        }
    } catch (e) {
        console.error('Erreur restauration brouillon:', e);
        showToast('Erreur', 'Impossible de restaurer le brouillon.', 'error');
        clearDraft(); // Clear potentially corrupted draft
    }
}


function clearDraft() {
    try {
        localStorage.removeItem(CONFIG.storageKey);
        console.log("🗑️ Brouillon effacé.");
    } catch (e) {
        console.error('Erreur suppression brouillon:', e);
    }
}

// ===== AUTO-SAVE =====
function initAutoSave() {
    setInterval(() => {
        // Only save if there's actually something to save
        if (Object.keys(checklistState.products).length > 0 || checklistState.commentaire !== '') {
            saveDraft();
        }
    }, CONFIG.autoSaveInterval);

    // Also save just before leaving the page
    window.addEventListener('beforeunload', saveDraft);
}