/**
 * ==========================================================================
 * CONTROLE PORTAL CAPTIVE - MIKROTIK HOTSPOT
 * ==========================================================================
 */

document.addEventListener("DOMContentLoaded", function () {
    // Seletores DOM das Etapas
    const stagePhone = document.getElementById("stage-phone");
    const stageAd = document.getElementById("stage-ad");

    // Seletores da Etapa de Telefone
    const phoneForm = document.getElementById("phone-form");
    const phoneInput = document.getElementById("phone");
    const phoneValidationError = document.getElementById("phone-validation-error");
    const btnPhoneSubmit = document.getElementById("btn-phone-submit");
    const phoneBtnSpinner = document.getElementById("phone-btn-spinner");
    const termsRequiredCheckbox = document.getElementById("terms-required");

    const errorMessageDiv = document.getElementById("error-message");
    const errorTextSpan = document.getElementById("error-text");
    
    // Seletores do Anúncio
    const timerElement = document.getElementById("countdown-timer");
    const msgElement = document.getElementById("countdown-msg");
    const btnContinue = document.getElementById("btn-continue");
    const adBtnSpinner = document.getElementById("ad-btn-spinner");

    // Seletores do formulário de login MikroTik (oculto)
    const mikrotikLoginForm = document.getElementById("mikrotik-login-form");
    const mkUsername = document.getElementById("mk-username");
    const mkPassword = document.getElementById("mk-password");

    // ==========================================================================
    // DETECÇÃO DE AMBIENTE (ÚNICA)
    // ==========================================================================
    function isMikrotik() {
        const action = mikrotikLoginForm.getAttribute("action");
        // Se a action for nula, contiver o placeholder $(), é ambiente de desenvolvimento
        if (!action || action.includes("$(")) {
            return false;
        }
        // Em ambiente MikroTik, a action é substituída (ex: /login)
        return true;
    }

    // Log de Desenvolvimento
    console.log("========== PORTAL ==========");
    console.log("Environment: " + (isMikrotik() ? "MikroTik RouterOS" : "Local/Development"));
    console.log("Action: " + mikrotikLoginForm.getAttribute("action"));
    console.log("MikroTik Detected: " + isMikrotik());

    // Verifica erros na URL
    const urlParams = new URLSearchParams(window.location.search);
    const errorParam = urlParams.get("error");
    const errorText = errorTextSpan.textContent.trim();
    
    if (errorParam || (errorText !== "" && errorText !== "$(error)")) {
        errorTextSpan.textContent = errorParam || errorText;
        errorMessageDiv.style.display = "block";
    }

    // ==========================================================================
    // FUNÇÕES DE FLUXO
    // ==========================================================================
    function showStage(stageToShow, stageToHide) {
        stageToHide.style.display = "none";
        stageToHide.classList.remove("active");
        stageToShow.style.display = "flex";
        stageToShow.classList.add("active");
    }

    function startAdCountdown() {
        let timeLeft = 10;
        timerElement.textContent = timeLeft;
        btnContinue.disabled = true;
        btnContinue.querySelector("span").textContent = `Aguarde (${timeLeft}s)`;
        adBtnSpinner.style.display = "none";
        
        const countdownInterval = setInterval(function() {
            timeLeft--;
            timerElement.textContent = timeLeft;
            btnContinue.querySelector("span").textContent = `Aguarde (${timeLeft}s)`;
            
            if (timeLeft <= 0) {
                clearInterval(countdownInterval);
                timerElement.style.display = "none";
                msgElement.textContent = "Internet liberada! Clique abaixo para conectar.";
                msgElement.className = "ad-liberated-msg";
                btnContinue.disabled = false;
                btnContinue.querySelector("span").textContent = "Conectar Agora";
            }
        }, 1000);
    }

    // ==========================================================================
    // AUTENTICAÇÃO (ÚNICA)
    // ==========================================================================
    function authenticate() {
    // Proteção contra duplo clique
    if (btnContinue.disabled) return;
    btnContinue.disabled = true;
    adBtnSpinner.style.display = "inline-block";
    btnContinue.querySelector("span").textContent = "Conectando...";

    console.log("Iniciando autenticação Trial...");

    if (isMikrotik()) {
        // O SEGREDO: No modo Trial não enviamos formulário POST.
        // Chamamos a URL mágica que o MikroTik injeta na variável $(link-login-only)
        const trialUrl = "$(link-login-only)?dst=$(link-orig-esc)&username=T-$(mac-esc)";
        
        console.log("Redirecionando para URL Trial do MikroTik...");
        window.location.href = trialUrl;
    } else {
        console.log("Redirecionando para alogin.html (Ambiente Local)...");
        setTimeout(function () {
            window.location.href = "alogin.html";
        }, 300);
    }
}

    // ==========================================================================
    // EVENT LISTENERS
    // ==========================================================================
    phoneForm.addEventListener("submit", function (e) {
        e.preventDefault();

        // Validação básica
        const phoneValue = phoneInput.value;
        const cleanPhone = phoneValue.replace(/\D/g, "");
        if (cleanPhone.length !== 10 && cleanPhone.length !== 11) {
            phoneInput.parentElement.classList.add("error");
            phoneValidationError.style.display = "block";
            return;
        }

        if (!termsRequiredCheckbox.checked) {
            alert("Você precisa aceitar os Termos e a Política de Privacidade para continuar.");
            return;
        }

        // Proteção contra duplo clique
        btnPhoneSubmit.disabled = true;
        phoneBtnSpinner.style.display = "inline-block";
        btnPhoneSubmit.querySelector("span").textContent = "Verificando...";

        // Transição
        sessionStorage.setItem("user_phone", phoneValue);
        showStage(stageAd, stagePhone);
        startAdCountdown();
    });

    btnContinue.addEventListener("click", authenticate);

    // Modal Logic
    const modal = document.getElementById("legal-modal");
    const modalTitle = document.getElementById("modal-title");
    const modalContent = document.getElementById("modal-content");
    const modalClose = document.getElementById("modal-close");
    const modalCloseFooter = document.getElementById("modal-close-footer");

    const btnTermsModal = document.getElementById("btn-terms-modal");
    const btnPrivacyModal = document.getElementById("btn-privacy-modal");
    const btnTermsFooter = document.getElementById("btn-terms-footer");
    const btnPrivacyFooter = document.getElementById("btn-privacy-footer");

    const texts = {
        terms: `
            <h3>1. Aceite dos Termos</h3>
            <p>Ao utilizar o nosso serviço de Hotspot Wi-Fi, você concorda expressamente em cumprir todos os termos e condições descritos neste instrumento. O acesso é gratuito e de uso corporativo/visitante.</p>
            
            <h3>2. Utilização do Serviço</h3>
            <p>Você concorda em usar o serviço Wi-Fi exclusivamente para fins lícitos. É terminantemente proibido o uso da rede para transferência de conteúdos ilegais, difamatórios, violação de direitos autorais ou qualquer atividade que comprometa a integridade da rede corporativa.</p>
            
            <h3>3. Limitação de Responsabilidade</h3>
            <p>Não nos responsabilizamos pela segurança dos dados trafegados no dispositivo do usuário durante a navegação, nem por eventuais perdas de conexão, velocidade oscilante ou danos causados por terceiros na internet.</p>
            
            <h3>4. Monitoramento da Conexão</h3>
            <p>Para segurança de nossa infraestrutura e conformidade legal, informações técnicas da sua conexão (IP, MAC Address e tempo de uso) podem ser coletadas para fins de auditoria, conforme exigido pela legislação vigente.</p>
        `,
        privacy: `
            <h3>1. Coleta de Informações</h3>
            <p>Coletamos o seu número de telefone e o endereço físico (MAC) do seu dispositivo de forma automatizada no momento em que você se autentica em nosso portal de Hotspot.</p>
            
            <h3>2. Finalidade dos Dados</h3>
            <p>Seus dados são armazenados de forma segura e exclusiva para garantir a autenticidade dos acessos, conformidade com a legislação local (Marco Civil da Internet) e, se expressamente autorizado, contato corporativo.</p>
            
            <h3>3. Compartilhamento de Dados</h3>
            <p>Não comercializamos ou transferimos suas informações pessoais para terceiros, excte mediante ordens judiciais formais emitidas por autoridades governamentais ou reguladoras competentes.</p>
            
            <h3>4. Segurança da Informação</h3>
            <p>Empregamos práticas de segurança de ponta e firewalls em nossos roteadores MikroTik para assegurar que seus dados coletados permaneçam confidenciais e protegidos contra vazamentos ou acessos não autorizados.</p>
        `
    };

    function openModal(title, contentHTML) {
        modalTitle.textContent = title;
        modalContent.innerHTML = contentHTML;
        modal.classList.add("active");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        modalClose.focus();
    }

    function closeModal() {
        modal.classList.remove("active");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    if (btnTermsModal) btnTermsModal.addEventListener("click", () => openModal("Termos e Condições de Uso", texts.terms));
    if (btnPrivacyModal) btnPrivacyModal.addEventListener("click", () => openModal("Política de Privacidade", texts.privacy));
    if (btnTermsFooter) btnTermsFooter.addEventListener("click", () => openModal("Termos e Condições de Uso", texts.terms));
    if (btnPrivacyFooter) btnPrivacyFooter.addEventListener("click", () => openModal("Política de Privacidade", texts.privacy));
    
    modalClose.addEventListener("click", closeModal);
    modalCloseFooter.addEventListener("click", closeModal);

    // Máscara de telefone mantida igual
    phoneInput.addEventListener("input", function (e) {
        let value = e.target.value.replace(/\D/g, "");
        if (value.length > 11) value = value.slice(0, 11);
        if (value.length > 0) value = "(" + value;
        if (value.length > 3) value = [value.slice(0, 3), ") ", value.slice(3)].join("");
        if (value.length > 9) {
            let hyphenPosition = value.length === 14 ? 10 : 9;
            value = [value.slice(0, hyphenPosition), "-", value.slice(hyphenPosition)].join("");
        }
        e.target.value = value;
    });
});
