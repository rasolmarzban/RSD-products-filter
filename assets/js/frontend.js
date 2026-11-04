(function () {
  "use strict";

  const debounce = (callback, delay = 250) => {
    let timeoutId;

    return (...args) => {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => callback(...args), delay);
    };
  };

  const initInstance = (instance) => {
    if (instance.dataset.rsdpfInit === "1") {
      return;
    }

    if (typeof window.RSDPF === "undefined" || !window.RSDPF.ajaxUrl || !window.RSDPF.nonce) {
      return;
    }

    instance.dataset.rsdpfInit = "1";

    let settings = {};
    try {
      settings = JSON.parse(instance.dataset.settings || "{}");
    } catch (error) {
      settings = {};
    }
    let initialSearch = instance.dataset.search ? String(instance.dataset.search).trim() : "";

    const resultsWrap = instance.querySelector(".rsdpf-results");
    const countNode = instance.querySelector(".rsdpf-found-count");
    const minRange = instance.querySelector(".rsdpf-range-min");
    const maxRange = instance.querySelector(".rsdpf-range-max");
    const priceLabels = instance.querySelectorAll(".rsdpf-price-label");
    const sliderProgress = instance.querySelector(".rsdpf-slider-progress");
    const resetButton = instance.querySelector(".rsdpf-reset");
    const mobileOpenButton = instance.querySelector(".rsdpf-mobile-open");
    const mobileCloseButton = instance.querySelector(".rsdpf-mobile-close");
    const mobileApplyButton = instance.querySelector(".rsdpf-mobile-apply");
    const mobileOverlay = instance.querySelector(".rsdpf-mobile-overlay");

    if (!resultsWrap || !minRange || !maxRange) {
      return;
    }

    const updateFoundCount = () => {
      if (!countNode) {
        return;
      }

      const loop = resultsWrap.querySelector(".rsdpf-results-inner");
      countNode.textContent = loop ? Number(loop.dataset.foundPosts || 0).toLocaleString("fa-IR") : "۰";
    };

    const buildUrlFromState = (payload) => {
      const url = new URL(window.location.href);
      const taxFilters = payload.tax_filters && typeof payload.tax_filters === "object" && !Array.isArray(payload.tax_filters) ? payload.tax_filters : {};
      const allowedTaxonomies = typeof window.RSDPF !== "undefined" && Array.isArray(window.RSDPF.allowedTaxonomies) ? window.RSDPF.allowedTaxonomies : [];

      const encodedTaxFilters = {};
      Object.keys(taxFilters).forEach((t) => {
        encodedTaxFilters[encodeURIComponent(t)] = true;
      });
      const encodedAllowed = {};
      allowedTaxonomies.forEach((t) => {
        encodedAllowed[encodeURIComponent(t)] = true;
      });

      Array.from(url.searchParams.keys()).forEach((key) => {
        if (key === "rsdpf_min_price" || key === "rsdpf_max_price" || key === "rsdpf_page" || key === "rsdpf_search") {
          url.searchParams.delete(key);
          return;
        }

        const rawKey = key.endsWith("[]") ? key.slice(0, -2) : key;

        const isDirect = Object.prototype.hasOwnProperty.call(taxFilters, rawKey) || allowedTaxonomies.indexOf(rawKey) !== -1;
        const isEncoded = Object.prototype.hasOwnProperty.call(encodedTaxFilters, rawKey) || Object.prototype.hasOwnProperty.call(encodedAllowed, rawKey);

        if (isDirect || isEncoded) {
          url.searchParams.delete(key);
        }
      });

      Object.keys(taxFilters).forEach((taxonomy) => {
        const termIds = Array.isArray(taxFilters[taxonomy]) ? taxFilters[taxonomy] : [];
        termIds.forEach((id) => {
          const doubleEncodedTax = encodeURIComponent(encodeURIComponent(taxonomy));
          url.searchParams.append(doubleEncodedTax + "[]", String(id));
        });
      });

      const minPrice = Number(payload.min_price);
      const maxPrice = Number(payload.max_price);
      const minPriceAbs = Number(minRange.min);
      const maxPriceAbs = Number(maxRange.max);

      if (!Number.isNaN(minPrice) && (minPriceAbs !== minPrice || maxPriceAbs !== maxPrice)) {
        if (!Number.isNaN(minPrice)) {
          url.searchParams.set("rsdpf_min_price", String(minPrice));
        }

        if (!Number.isNaN(maxPrice)) {
          url.searchParams.set("rsdpf_max_price", String(maxPrice));
        }
      }

      const search = typeof payload.search === "string" ? String(payload.search).trim() : "";
      if (search !== "") {
        url.searchParams.set("rsdpf_search", search);
      }

      const page = Number(payload.page) > 1 ? Number(payload.page) : 0;
      if (page > 0) {
        url.searchParams.set("rsdpf_page", String(page));
      }

      return url.toString();
    };

    const updateUrlFromState = (payload, replace = false) => {
      if (!window.history || typeof window.history.pushState !== "function") {
        return;
      }

      const newUrl = buildUrlFromState(payload);
      if (newUrl === window.location.href) {
        return;
      }

      if (replace) {
        window.history.replaceState({ rsdpf: payload }, "", newUrl);
      } else {
        window.history.pushState({ rsdpf: payload }, "", newUrl);
      }
    };

    const normalizePriceRanges = () => {
      const minValue = Number(minRange.value);
      const maxValue = Number(maxRange.value);

      if (minValue > maxValue) {
        if (document.activeElement === minRange) {
          maxRange.value = minRange.value;
        } else {
          minRange.value = maxRange.value;
        }
      }

      const min = Number(minRange.min);
      const max = Number(maxRange.max);
      const currentMin = Number(minRange.value);
      const currentMax = Number(maxRange.value);
      const start = ((currentMin - min) / (max - min || 1)) * 100;
      const end = ((currentMax - min) / (max - min || 1)) * 100;

      if (sliderProgress) {
        sliderProgress.style.left = `${start}%`;
        sliderProgress.style.right = `${100 - end}%`;
      }

      if (priceLabels[0]) {
        priceLabels[0].textContent = currentMin.toLocaleString("fa-IR");
      }

      if (priceLabels[1]) {
        priceLabels[1].textContent = currentMax.toLocaleString("fa-IR");
      }
    };

    const collectState = (page = 1) => {
      const taxFilters = {};

      instance.querySelectorAll(".rsdpf-filter-input:checked").forEach((input) => {
        const taxonomy = input.dataset.taxonomy;

        if (!taxonomy) {
          return;
        }

        if (!taxFilters[taxonomy]) {
          taxFilters[taxonomy] = [];
        }

        taxFilters[taxonomy].push(Number(input.value));
      });

      const currentSearch = instance.dataset.search ? String(instance.dataset.search).trim() : "";

      return {
        page,
        min_price: Number(minRange.value),
        max_price: Number(maxRange.value),
        search: currentSearch,
        tax_filters: taxFilters,
        settings,
      };
    };

    const applyStateToUi = (payload) => {
      if (!payload || typeof payload !== "object") {
        return;
      }

      // Restore taxonomy filters.
      const taxFilters = payload.tax_filters && typeof payload.tax_filters === "object" && !Array.isArray(payload.tax_filters) ? payload.tax_filters : {};

      instance.querySelectorAll(".rsdpf-filter-input").forEach((input) => {
        const taxonomy = input.dataset.taxonomy;
        const termId = Number(input.value);
        const list = Array.isArray(taxFilters[taxonomy]) ? taxFilters[taxonomy] : [];
        input.checked = list.indexOf(termId) !== -1;
      });

      // Restore price ranges.
      if (typeof payload.min_price === "number" || typeof payload.min_price === "string") {
        const minPrice = Number(payload.min_price);
        if (!Number.isNaN(minPrice) && minRange.min !== "" && minPrice >= Number(minRange.min) && minPrice <= Number(minRange.max)) {
          minRange.value = String(minPrice);
        }
      }

      if (typeof payload.max_price === "number" || typeof payload.max_price === "string") {
        const maxPrice = Number(payload.max_price);
        if (!Number.isNaN(maxPrice) && maxRange.max !== "" && maxPrice >= Number(maxRange.min) && maxPrice <= Number(maxRange.max)) {
          maxRange.value = String(maxPrice);
        }
      }

      normalizePriceRanges();

      if (typeof payload.search === "string") {
        const normalized = String(payload.search).trim();
        instance.dataset.search = normalized;
        initialSearch = normalized;
      }
    };

    const getStateFromUrl = () => {
      const url = new URL(window.location.href);
      const taxFilters = {};
      const allowedTaxonomies = typeof window.RSDPF !== "undefined" && Array.isArray(window.RSDPF.allowedTaxonomies) ? window.RSDPF.allowedTaxonomies : [];

      const knownTaxonomies = new Set(allowedTaxonomies);
      instance.querySelectorAll(".rsdpf-filter-input").forEach((input) => {
        const t = input.dataset.taxonomy;
        if (t) {
          knownTaxonomies.add(t);
        }
      });

      const taxonomyAliases = new Map();
      knownTaxonomies.forEach((tax) => {
        taxonomyAliases.set(tax, tax);
        try {
          taxonomyAliases.set(encodeURIComponent(tax), tax);
        } catch (e) {}
        try {
          taxonomyAliases.set(decodeURIComponent(tax), tax);
        } catch (e) {}
        try {
          taxonomyAliases.set(decodeURIComponent(decodeURIComponent(tax)), tax);
        } catch (e) {}
      });

      const safeDecode = (value, levels = 3) => {
        let current = value;
        for (let i = 0; i < levels; i++) {
          const mapped = taxonomyAliases.get(current);
          if (mapped) {
            return mapped;
          }
          try {
            current = decodeURIComponent(current);
          } catch (e) {
            return taxonomyAliases.get(current) || null;
          }
        }
        return taxonomyAliases.get(current) || null;
      };

      const collected = new Map();
      Array.from(url.searchParams.keys()).forEach((key) => {
        const hasBrackets = key.endsWith("[]");
        const rawKey = hasBrackets ? key.slice(0, -2) : key;
        const resolvedTax = safeDecode(rawKey);
        if (!resolvedTax) {
          return;
        }
        const values = url.searchParams.getAll(key);
        values.forEach((v) => {
          if (typeof v !== "string" || v === "") {
            return;
          }
          if (!collected.has(resolvedTax)) {
            collected.set(resolvedTax, []);
          }
          collected.get(resolvedTax).push(v);
        });
      });

      collected.forEach((values, tax) => {
        const ids = values.map((value) => Number(value)).filter((value) => Number.isFinite(value) && value > 0);
        if (ids.length > 0) {
          taxFilters[tax] = ids;
        }
      });

      const minPrice = url.searchParams.get("rsdpf_min_price");
      const maxPrice = url.searchParams.get("rsdpf_max_price");
      const page = url.searchParams.get("rsdpf_page");
      let search = url.searchParams.get("rsdpf_search");
      if (typeof search === "string") {
        try {
          search = decodeURIComponent(search);
        } catch (e) {}
      }

      const output = {
        page: page ? Math.max(1, Number(page)) : 1,
        min_price: minPrice != null ? Number(minPrice) : Number(minRange.value),
        max_price: maxPrice != null ? Number(maxPrice) : Number(maxRange.value),
        search: typeof search === "string" ? search : initialSearch,
        tax_filters: taxFilters,
        settings,
      };

      if (Number.isNaN(output.min_price)) output.min_price = Number(minRange.value);
      if (Number.isNaN(output.max_price)) output.max_price = Number(maxRange.value);
      if (!Number.isFinite(output.page)) output.page = 1;

      return output;
    };

    const setLoading = (loading) => {
      instance.classList.toggle("is-loading", loading);
    };

    const toggleMobileModal = (shouldOpen) => {
      instance.classList.toggle("is-mobile-open", shouldOpen);
      if (shouldOpen) {
        document.body.classList.add("rsdpf-modal-open");
      } else {
        document.body.classList.remove("rsdpf-modal-open");
      }
    };

    const loadResults = debounce((page = 1, options = {}) => {
      setLoading(true);

      const payload = collectState(page);
      const shouldUpdateUrl = typeof options.updateUrl === "boolean" ? options.updateUrl : true;
      const replaceUrl = typeof options.replaceUrl === "boolean" ? options.replaceUrl : false;

      const body = new URLSearchParams();
      body.set("action", "rsdpf_filter_products");
      body.set("nonce", window.RSDPF.nonce);
      body.set("payload_json", JSON.stringify(payload));

      fetch(window.RSDPF.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
      })
        .then((response) => response.json())
        .then((response) => {
          if (!response.success || !response.data) {
            throw new Error("Request failed");
          }

          resultsWrap.innerHTML = response.data.html;
          updateFoundCount();

          if (shouldUpdateUrl) {
            updateUrlFromState(payload, replaceUrl);
          }
        })
        .catch(() => {
          const emptyMessage = window.RSDPF && window.RSDPF.i18n && window.RSDPF.i18n.empty ? window.RSDPF.i18n.empty : "محصولی پیدا نشد.";
          resultsWrap.innerHTML = `<div class="rsdpf-empty-state">${emptyMessage}</div>`;
          updateFoundCount();
        })
        .finally(() => {
          setLoading(false);
        });
    }, 300);

    instance.querySelectorAll(".rsdpf-box-toggle").forEach((button) => {
      button.addEventListener("click", () => {
        button.parentElement.classList.toggle("is-collapsed");
      });
    });

    instance.querySelectorAll(".rsdpf-term-search").forEach((searchInput) => {
      searchInput.addEventListener("input", () => {
        const keyword = searchInput.value.trim().toLowerCase();
        const filterBox = searchInput.closest(".rsdpf-filter-box");

        filterBox.querySelectorAll(".rsdpf-term-item").forEach((item) => {
          const text = item.textContent.trim().toLowerCase();
          item.style.display = text.includes(keyword) ? "" : "none";
        });
      });
    });

    instance.addEventListener("change", (event) => {
      if (event.target.classList.contains("rsdpf-filter-input")) {
        loadResults(1);
      }
    });

    minRange.addEventListener("input", () => {
      normalizePriceRanges();
      loadResults(1);
    });

    maxRange.addEventListener("input", () => {
      normalizePriceRanges();
      loadResults(1);
    });

    if (resetButton) {
      resetButton.addEventListener("click", () => {
        instance.querySelectorAll(".rsdpf-filter-input").forEach((input) => {
          input.checked = false;
        });

        instance.querySelectorAll(".rsdpf-term-search").forEach((input) => {
          input.value = "";
        });

        instance.querySelectorAll(".rsdpf-term-item").forEach((item) => {
          item.style.display = "";
        });

        minRange.value = minRange.min;
        maxRange.value = maxRange.max;
        normalizePriceRanges();
        loadResults(1, { replaceUrl: true });
      });
    }

    if (mobileOpenButton) {
      mobileOpenButton.addEventListener("click", () => {
        toggleMobileModal(true);
      });
    }

    if (mobileCloseButton) {
      mobileCloseButton.addEventListener("click", () => {
        toggleMobileModal(false);
      });
    }

    if (mobileApplyButton) {
      mobileApplyButton.addEventListener("click", () => {
        toggleMobileModal(false);
      });
    }

    if (mobileOverlay) {
      mobileOverlay.addEventListener("click", () => {
        toggleMobileModal(false);
      });
    }

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && instance.classList.contains("is-mobile-open")) {
        toggleMobileModal(false);
      }
    });

    window.addEventListener("resize", () => {
      if (window.innerWidth > 767 && instance.classList.contains("is-mobile-open")) {
        toggleMobileModal(false);
      }
    });

    const loadFromHistoryState = (state) => {
      const urlState = getStateFromUrl();
      const activePayload = state && typeof state === "object" && state.rsdpf ? state.rsdpf : urlState;

      applyStateToUi(activePayload);

      const page = Number(activePayload.page);
      const safePage = Number.isFinite(page) && page > 0 ? page : 1;

      loadResults(safePage, { updateUrl: false });
    };

    window.addEventListener("popstate", (event) => {
      if (!instance.isConnected) {
        return;
      }

      loadFromHistoryState(event.state || null);
    });

    instance.addEventListener("click", (event) => {
      const pageButton = event.target.closest(".rsdpf-page-btn");

      if (!pageButton) {
        return;
      }

      loadResults(Number(pageButton.dataset.page || 1));
    });

    // Bootstrap initial URL state for shared/copied links and ensure UI always matches URL.
    const initialUrlState = getStateFromUrl();
    const initialTaxFilters = Object.prototype.hasOwnProperty.call(initialUrlState, "tax_filters") ? initialUrlState.tax_filters : {};
    const hasInitialFilters = Object.keys(initialTaxFilters).some((key) => {
      return Array.isArray(initialTaxFilters[key]) && initialTaxFilters[key].length > 0;
    });
    const hasInitialCustomPrice = initialUrlState.min_price !== Number(minRange.min) || initialUrlState.max_price !== Number(maxRange.max);
    const hasInitialCustomPage = Number(initialUrlState.page) > 1;
    const hasInitialCustomSearch = typeof initialUrlState.search === "string" && initialUrlState.search.trim() !== "" && initialUrlState.search !== initialSearch;

    if (hasInitialFilters || hasInitialCustomPrice || hasInitialCustomPage || hasInitialCustomSearch) {
      applyStateToUi(initialUrlState);
    }

    normalizePriceRanges();
    updateFoundCount();
  };

  const scanAndInit = () => {
    document.querySelectorAll(".rsdpf").forEach(initInstance);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", scanAndInit, { once: true });
  } else {
    scanAndInit();
  }

  const observer = new MutationObserver(() => {
    scanAndInit();
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
