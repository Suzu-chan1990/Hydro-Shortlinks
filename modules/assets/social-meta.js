document.addEventListener("DOMContentLoaded", () => {
  const titleInput = document.querySelector('input[name="hydro_meta_title"]');
  const descInput = document.querySelector('textarea[name="hydro_meta_desc"]');
  const imgInput = document.querySelector('input[name="hydro_meta_img"]');
  const fetchButton = document.getElementById("hydro-auto-fetch");
  const preview = document.querySelector(".hydro-meta-preview");

  if (!preview) return;

  // Elemente der Vorschau
  const previewTitle = preview.querySelector("h4");
  const previewDesc = preview.querySelector("p");

  // 🔁 Aktualisiert die Vorschau live
  const updatePreview = () => {
    const title = titleInput?.value?.trim() || "Social Title Vorschau";
    const desc = descInput?.value?.trim() || "Social Description Vorschau";
    const img = imgInput?.value?.trim();

    previewTitle.textContent = title;
    previewDesc.textContent = desc;

    let imgTag = preview.querySelector("img");
    if (img) {
      if (!imgTag) {
        imgTag = document.createElement("img");
        preview.prepend(imgTag);
      }
      imgTag.src = img;
    } else if (imgTag) {
      imgTag.remove();
    }
  };

  [titleInput, descInput, imgInput].forEach((el) => {
    if (el) el.addEventListener("input", updatePreview);
  });

  // 🌗 Theme Toggle (Light/Dark)
  const themeToggle = document.createElement("div");
  themeToggle.className = "hydro-meta-theme-toggle";
  themeToggle.textContent = "🌓 Theme wechseln";
  preview.after(themeToggle);
  themeToggle.addEventListener("click", () => preview.classList.toggle("dark"));

  // ⚙️ Auto-Fetch Funktion
  if (fetchButton) {
    fetchButton.addEventListener("click", async () => {
      fetchButton.disabled = true;
      fetchButton.textContent = "⏳ Lädt...";

      try {
        // Hole die Ziel-URL aus deinem Shortlink-Feld (Standard-URL-Feld)
        const targetField = document.querySelector('input[name="hydro_target_url"]') || null;
        const targetURL = targetField ? targetField.value.trim() : "";

        if (!targetURL) {
          alert("Keine Ziel-URL gefunden!");
          fetchButton.textContent = "🔄 Auto-Fetch von Ziel-URL";
          fetchButton.disabled = false;
          return;
        }

        const formData = new FormData();
        formData.append("action", "hydro_meta_fetch");
        formData.append("url", targetURL);
        formData.append("nonce", hydroMetaAjax.nonce);

        const res = await fetch(hydroMetaAjax.ajax_url, { method: "POST", body: formData });
        const data = await res.json();

        if (data.success) {
          if (data.data.title && titleInput) titleInput.value = data.data.title;
          if (data.data.desc && descInput) descInput.value = data.data.desc;
          if (data.data.image && imgInput) imgInput.value = data.data.image;
          updatePreview();
        } else {
          alert(data.data?.message || "Fehler beim Abrufen der Daten.");
        }
      } catch (e) {
        alert("Verbindungsfehler oder Timeout.");
      }

      fetchButton.textContent = "🔄 Auto-Fetch von Ziel-URL";
      fetchButton.disabled = false;
    });
  }

  // Initiale Anzeige
  updatePreview();
});
