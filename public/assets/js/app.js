document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        const message = form.getAttribute("data-confirm") || "Lanjutkan aksi ini?";
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll("img").forEach((image) => {
    image.addEventListener("error", () => {
        image.style.opacity = "0";
        image.parentElement?.classList.add("image-fallback");
    }, { once: true });
});
