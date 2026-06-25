/**
 * ==========================================================================
 * CONTROLE PORTAL CAPTIVE - MIKROTIK HOTSPOT
 * Validação de telefone, formatação de máscara de telefone e controle de modais.
 * ==========================================================================
 */

document.addEventListener("DOMContentLoaded", function () {
    // Seletores DOM
    const form = document.getElementById("hotspot-form");
    const phoneInput = document.getElementById("phone");
    const phoneValidationError = document.getElementById("phone-validation-error");
    const btnSubmit = document.getElementById("btn-submit");
    const btnSpinner = document.getElementById("btn-spinner");
    
    // Seletores do Modal
    const modal = document.getElementById("legal-modal");
    const modalTitle = document.getElementById("modal-title");
    const modalContent = document.getElementById("modal-content");
    const btnTerms = document.getElementById("btn-terms");
    const btnPrivacy = document.getElementById("btn-privacy");
    const modalClose = document.getElementById("modal-close");
    const modalCloseFooter = document.getElementById("modal-close-footer");

    // Conteúdos Textuais dos Termos e Políticas em HTML Puro (Visual Elegante)
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
            <p>Não comercializamos ou transferimos suas informações pessoais para terceiros, exceto mediante ordens judiciais formais emitidas por autoridades governamentais ou reguladoras competentes.</p>
            
            <h3>4. Segurança da Informação</h3>
            <p>Empregamos práticas de segurança de ponta e firewalls em nossos roteadores MikroTik para assegurar que seus dados coletados permaneçam confidenciais e protegidos contra vazamentos ou acessos não autorizados.</p>
        `
    };

    /**
     * MÁSCARA DINÂMICA DE TELEFONE (Padrão Brasileiro e Internacional)
     * Formata automaticamente enquanto o usuário digita: (99) 99999-9999 ou (99) 9999-9999
     */
    phoneInput.addEventListener("input", function (e) {
        let value = e.target.value;
        
        // Remove tudo o que não for número
        value = value.replace(/\D/g, "");
        
        // Limita a quantidade máxima para 11 dígitos
        if (value.length > 11) {
            value = value.slice(0, 11);
        }

        // Aplica formatação passo a passo
        if (value.length > 0) {
            value = "(" + value;
        }
        if (value.length > 3) {
            value = [value.slice(0, 3), ") ", value.slice(3)].join("");
        }
        if (value.length > 9) {
            // Se tiver 11 dígitos (celular moderno), coloca o hífen na posição 10. Se 10 dígitos, coloca na posição 9.
            let hyphenPosition = value.length === 14 ? 10 : 9;
            value = [value.slice(0, hyphenPosition), "-", value.slice(hyphenPosition)].join("");
        }

        e.target.value = value;
    });

    /**
     * VALIDAÇÃO BÁSICA DO TELEFONE
     * Verifica se possui os dígitos mínimos necessários (DDD de 2 dígitos + número de 8 ou 9 dígitos)
     */
    function isValidPhone(phone) {
        // Remove formatação para validar os dígitos limpos
        const cleanPhone = phone.replace(/\D/g, "");
        // Números residenciais têm 10 dígitos; celulares têm 11. Ambos são válidos.
        return cleanPhone.length === 10 || cleanPhone.length === 11;
    }

    /**
     * SUBMISSÃO DO FORMULÁRIO COM FEEDBACK DE CARREGAMENTO
     */
    form.addEventListener("submit", function (e) {
        e.preventDefault(); // Impede a ação padrão para fazermos a validação antes

        const phoneValue = phoneInput.value;

        if (!isValidPhone(phoneValue)) {
            // Exibe mensagem de erro visual
            phoneInput.parentElement.classList.add("error");
            phoneValidationError.style.display = "block";
            phoneInput.focus();
            return;
        }

        // Se o telefone for válido, remove as classes de erro
        phoneInput.parentElement.classList.remove("error");
        phoneValidationError.style.display = "none";

        // Feedback visual do botão (efeito de carregamento)
        btnSubmit.disabled = true;
        btnSpinner.style.display = "inline-block";
        btnSubmit.querySelector("span").textContent = "Verificando...";

        // Em ambientes reais MikroTik, este script coletaria o telefone
        // e enviaria os dados ao formulário oculto do MikroTik, que por sua vez
        // autentica o usuário no roteador.
        
        // Exemplo de integração nativa MikroTik:
        /*
        if (document.sendin) {
            // Atribui o telefone limpo ou formatado como usuário no MikroTik
            document.sendin.username.value = phoneValue.replace(/\D/g, "");
            document.sendin.password.value = "hotspot_free_access"; // Senha padrão opcional
            
            // Submete o form interno do roteador para efetivar a liberação do Wi-Fi
            setTimeout(function() {
                document.sendin.submit();
            }, 1000);
            return;
        }
        */

        // Simulação local: aguarda 1.5s para simular verificação de rede e redireciona para liberado.html
        setTimeout(function () {
            // Salva o telefone formatado na sessionStorage para mostrar na página de sucesso
            sessionStorage.setItem("user_phone", phoneValue);
            // Redireciona para a página liberado.html
            window.location.href = "anuncio.html";
        }, 1200);
    });

    /**
     * CONTROLE DE MODAL - ABERTURA E FECHAMENTO ELEGANTES
     */
    function openModal(title, contentHTML) {
        modalTitle.textContent = title;
        modalContent.innerHTML = contentHTML;
        modal.classList.add("active");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden"; // Impede scroll no fundo
        modalClose.focus();
    }

    function closeModal() {
        modal.classList.remove("active");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = ""; // Restaura scroll
        btnTerms.focus();
    }

    // Eventos do Modal
    btnTerms.addEventListener("click", () => openModal("Termos e Condições de Uso", texts.terms));
    btnPrivacy.addEventListener("click", () => openModal("Política de Privacidade", texts.privacy));
    
    modalClose.addEventListener("click", closeModal);
    modalCloseFooter.addEventListener("click", closeModal);

    // Fechar ao clicar fora da área interna do modal (na overlay)
    modal.addEventListener("click", function (e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Fechar com a tecla ESC
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && modal.classList.contains("active")) {
            closeModal();
        }
    });
});
