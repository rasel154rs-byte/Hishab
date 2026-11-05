(function () {
  "use strict";

  // ===== হ্যামবার্গার মেনু =====
  const menuBtn = document.getElementById("menuBtn");
  const navLinks = document.getElementById("navLinks");

  if (menuBtn && navLinks) {
    menuBtn.addEventListener("click", function () {
      const isOpen = navLinks.classList.toggle("open");
      menuBtn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });
  }

  // ===== থিম টগল =====
  const themeBtn = document.getElementById("themeBtn");
  if (themeBtn) {
    themeBtn.addEventListener("click", function () {
      const html = document.documentElement;
      const next =
        html.getAttribute("data-theme") === "dark" ? "light" : "dark";
      html.setAttribute("data-theme", next);
      themeBtn.textContent = next === "dark" ? "☀️" : "🌙";
      saveSetting("theme", next);
    });
  }

  // ===== প্রিমিয়াম কাস্টম কনফার্ম মডাল =====
  window.showPremiumConfirm = function (message, onConfirm) {
    // মডালের HTML তৈরি করা
    const modalHTML = `
        <div class="custom-confirm-box">
            <div class="custom-confirm-icon">!</div>
            <h3>আপনি কি নিশ্চিত?</h3>
            <p>${message}</p>
            <div class="custom-confirm-actions">
                <button class="custom-confirm-cancel">বাতিল</button>
                <button class="custom-confirm-ok">হ্যাঁ, নিশ্চিত</button>
            </div>
        </div>
    `;

    // ওভারলে তৈরি ও বডিতে যুক্ত করা
    const overlay = document.createElement("div");
    overlay.className = "custom-confirm-overlay";
    overlay.innerHTML = modalHTML;
    document.body.appendChild(overlay);

    // অ্যানিমেশন দেখানোর জন্য display flex করা
    setTimeout(() => {
      overlay.style.display = "flex";
    }, 10);

    // বাটন ইভেন্ট লিসেনার
    const btnCancel = overlay.querySelector(".custom-confirm-cancel");
    const btnOk = overlay.querySelector(".custom-confirm-ok");

    function closeModal() {
      overlay.style.display = "none";
      setTimeout(() => overlay.remove(), 200); // অ্যানিমেশন শেষে ডোম থেকে মুছে ফেলা
    }

    btnCancel.addEventListener("click", closeModal);
    btnOk.addEventListener("click", function () {
      closeModal();
      if (typeof onConfirm === "function") onConfirm();
    });

    // ওভারলের বাইরে ক্লিক করলে বন্ধ হবে
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) closeModal();
    });
  };

  // ===== ভাষা বদল =====
  const langSelect = document.getElementById("langSelect");
  if (langSelect) {
    langSelect.addEventListener("change", function () {
      const lang = langSelect.value;
      // সেভ করে পেজ রিলোড — সবচেয়ে নির্ভরযোগ্য
      saveSetting("lang", lang, function () {
        window.location.reload();
      });
    });
  }

  // ===== সেটিং সেভ (AJAX) =====
  function saveSetting(key, value, callback) {
    const url = (window.APP_URL ? window.APP_URL + "/" : "") + "theme-save.php";
    fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body:
        "key=" +
        encodeURIComponent(key) +
        "&value=" +
        encodeURIComponent(value),
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (!data.ok) console.warn("Save failed");
        if (callback) callback();
      })
      .catch(function (err) {
        console.error("Save error:", err);
        if (callback) callback();
      });
  }
})();
