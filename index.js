// Counters the status box renders. Anything missing or non-numeric in a
// server response becomes 0, so the box never shows blank numbers.
const STATUS_COUNTERS = [
  "eligible", "indexed", "chunks", "compatible",
  "incompatible", "pending", "failed", "stale"
];

function normalizeStatus(status) {
  const source = status && typeof status === "object" ? status : {};
  const result = {
    state: source.state || "loading",
    requiresRebuild: source.requiresRebuild === true,
    complete: source.complete === true,
    lastUpdated: source.lastUpdated || null,
    error: source.error || ""
  };

  for (const key of STATUS_COUNTERS) {
    const value = Number(source[key]);
    result[key] = Number.isFinite(value) ? value : 0;
  }

  const mode = source.searchMode && typeof source.searchMode === "object"
    ? source.searchMode
    : {};
  result.searchMode = {
    active: mode.active === true,
    label: mode.label || "unbekannt",
    detail: mode.detail || ""
  };

  return result;
}

panel.plugin("bitbetter/fabby", {
  sections: {
    "fabby-rag": {
      mixins: ["section"],
      data() {
        return {
          isLoading: true,
          headline: null,
          current: normalizeStatus(null),
          running: false,
          pauseRequested: false,
          runtimeError: ""
        };
      },
      // Kirby does not hand a section's computed props to the component when
      // the tab renders; the tab carries only {name, type}. Like Kirby's own
      // stats section, the values have to be fetched through the section
      // mixin's load(), which calls the section endpoint (index.php:
      // sections -> fabby-rag -> props). Until that answer arrives the box
      // shows a loading state with every counter at 0.
      async created() {
        try {
          const response = await this.load();
          this.headline = response.label || null;
          this.current = normalizeStatus(response.status);
        } catch (error) {
          this.current = normalizeStatus({ state: "error" });
          this.runtimeError = error && error.message
            ? error.message
            : "Der Status der Wissensdatenbank konnte nicht geladen werden.";
        } finally {
          this.isLoading = false;
        }
      },
      computed: {
        title() {
          if (this.running) {
            return this.current.indexed === 0
              ? "Wissensdatenbank wird aufgebaut"
              : "Wissensdatenbank wird aktualisiert";
          }

          return {
            loading: "Wissensdatenbank wird geladen",
            ready: "Wissensdatenbank ist aktuell",
            updating: "Wissensdatenbank wird aktualisiert",
            error: "Wissensdatenbank ist nicht vollständig",
            empty: "Keine geeigneten Seiten gefunden",
            incomplete: "Wissensdatenbank ist nicht vollständig",
            not_configured: "Wissensdatenbank noch nicht eingerichtet"
          }[this.current.state] || "Wissensdatenbank";
        },
        theme() {
          if (this.runtimeError || this.current.state === "error") {
            return "negative";
          }

          if (this.isLoading) {
            return "info";
          }

          if (this.current.complete) {
            return "positive";
          }

          return "notice";
        },
        icon() {
          if (this.running || this.isLoading) return "loader";
          if (this.runtimeError || this.current.state === "error") return "alert";
          if (this.current.complete) return "check";
          return "search";
        },
        percent() {
          if (this.current.eligible < 1) return 0;
          return Math.min(100, Math.round(
            this.current.indexed / this.current.eligible * 100
          ));
        },
        primaryLabel() {
          if (this.current.requiresRebuild) {
            return "Vollständigen Neuaufbau starten";
          }
          if (this.current.failed > 0 || this.current.state === "error") {
            return "Erneut versuchen";
          }
          if (this.current.pending > 0 && this.current.indexed === 0) {
            return "Einrichtung fortsetzen";
          }
          if (this.current.pending > 0) {
            return "Änderungen jetzt verarbeiten";
          }
          return "Wissensdatenbank jetzt einrichten";
        },
        // The button group carries a top margin, so it must not be rendered
        // when both of its buttons are hidden (index complete, nothing
        // running) — an empty group would leave a blank strip at the
        // bottom of the box.
        showButtons() {
          if (this.isLoading || this.current.eligible < 1) return false;
          return this.running || !this.current.complete;
        },
        searchModeText() {
          const mode = this.current.searchMode;
          return mode.detail
            ? `Suchmodus: ${mode.label} (${mode.detail})`
            : `Suchmodus: ${mode.label}`;
        },
        lastUpdated() {
          if (!this.current.lastUpdated) return "";
          return new Intl.DateTimeFormat("de-DE", {
            dateStyle: "medium",
            timeStyle: "short"
          }).format(new Date(this.current.lastUpdated * 1000));
        }
      },
      methods: {
        async primaryAction() {
          if (this.current.requiresRebuild) {
            this.confirmForce();
            return;
          }

          if (this.current.pending > 0 && this.current.failed === 0) {
            await this.processQueue();
            return;
          }

          await this.prepareAndProcess(false);
        },
        async prepareAndProcess(force) {
          this.runtimeError = "";
          this.pauseRequested = false;

          try {
            const response = await this.$api.post(
              force ? "fabby/rag/prepare-force" : "fabby/rag/prepare"
            );
            this.current = normalizeStatus(response.status);

            if (response.busy) {
              this.runtimeError = "Die Wissensdatenbank wird bereits verarbeitet.";
              return;
            }

            await this.processQueue();
          } catch (error) {
            this.showError(error);
          }
        },
        async processQueue() {
          this.runtimeError = "";
          this.pauseRequested = false;
          this.running = true;

          try {
            while (this.current.pending > 0 && !this.pauseRequested) {
              const before = this.current.pending;
              const response = await this.$api.post("fabby/rag/process");
              const result = response.result;
              this.current = normalizeStatus(response.status);

              if (result.busy) {
                this.runtimeError = "Die Wissensdatenbank wird bereits verarbeitet.";
                break;
              }

              if (result.errors.length > 0) {
                this.runtimeError = result.errors.slice(0, 2).join("; ");
                break;
              }

              if (this.current.pending >= before) {
                this.runtimeError = "Die Verarbeitung macht momentan keinen Fortschritt.";
                break;
              }

              // Give Vue a paint opportunity between the short requests.
              await new Promise((resolve) => window.setTimeout(resolve, 50));
            }
          } catch (error) {
            this.showError(error);
          } finally {
            this.running = false;
          }

          if (this.pauseRequested) {
            this.$panel.notification.info("Verarbeitung pausiert. Sie kann später fortgesetzt werden.");
          } else if (this.current.complete) {
            this.$panel.notification.success("Die Wissensdatenbank ist vollständig und aktuell.");
            await this.$panel.view.refresh();
          } else if (this.runtimeError) {
            this.$panel.notification.error(this.runtimeError);
          }
        },
        pause() {
          this.pauseRequested = true;
        },
        confirmForce() {
          this.$panel.dialog.open({
            component: "k-text-dialog",
            props: {
              text: `<p><strong>Gesamte Wissensdatenbank zurücksetzen?</strong></p>
                <p>Der bestehende Index wird geleert und alle ${this.current.eligible}
                Seiten werden erneut an die Embedding-API geschickt. Dadurch entstehen
                API-Kosten. Der Vorgang ist nur nach Änderungen an Modell, Basis-URL
                oder Abschnittsgröße nötig.</p>`,
              submitButton: "Kostenpflichtig zurücksetzen",
              theme: "negative"
            },
            on: {
              submit: () => {
                this.$panel.dialog.close();
                this.prepareAndProcess(true);
              }
            }
          });
        },
        showError(error) {
          this.runtimeError = error && error.message
            ? error.message
            : "Die Verarbeitung ist fehlgeschlagen.";
          this.running = false;
          this.$panel.notification.error(this.runtimeError);
        }
      },
      // Root is <k-section>, not a bare <section>: Kirby spaces adjacent
      // sections via the CSS rule `.k-section + .k-section`, and all of its
      // own sections render through this wrapper. A bare element gets no gap
      // above it, which glued this box to the preceding field's help text.
      template: `
        <k-section class="k-fabby-rag-section">
          <!-- Same look as a headline field, so the RAG group matches the
               System-Prompt and Tools groups above it. -->
          <k-headline v-if="headline" class="h2 k-fabby-rag-headline">
            {{ headline }}
          </k-headline>
          <k-box :theme="theme" class="k-fabby-rag-status">
            <div class="k-fabby-rag-status__heading">
              <k-icon :type="icon" />
              <div>
                <h2>{{ title }}</h2>
                <p v-if="isLoading">
                  Status wird geladen …
                </p>
                <p v-else-if="current.state === 'empty'">
                  Auf dieser Website wurden keine Seiten mit ausreichend Inhalt gefunden.
                </p>
                <p v-else-if="current.state === 'not_configured'">
                  Auf dieser Website wurden {{ current.eligible }} geeignete Seiten gefunden.
                </p>
                <p v-else>
                  {{ current.indexed }} von {{ current.eligible }} Seiten eingebettet
                  · {{ current.chunks }} Textabschnitte gespeichert
                </p>

                <div
                  v-if="!isLoading && current.eligible > 0 && current.indexed < current.eligible"
                  class="k-fabby-rag-progress"
                >
                  <div
                    class="k-fabby-rag-progress__bar"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                  >
                    <span :style="{ width: percent + '%' }"></span>
                  </div>
                  <strong>{{ percent }} %</strong>
                </div>

                <p v-if="current.pending > 0" class="k-fabby-rag-detail">
                  {{ current.pending }} {{ current.pending === 1 ? 'Änderung wartet' : 'Änderungen warten' }} noch.
                  Der Vorgang kann bei Bedarf später fortgesetzt werden.
                </p>
                <p v-else-if="current.complete" class="k-fabby-rag-detail">
                  Keine Änderungen offen.
                  <span v-if="lastUpdated">Letzte Aktualisierung: {{ lastUpdated }} Uhr.</span>
                </p>
                <p v-if="!isLoading" class="k-fabby-rag-detail">
                  {{ searchModeText }}
                </p>
                <p v-if="runtimeError || current.error" class="k-fabby-rag-error">
                  {{ runtimeError || current.error }}
                </p>

                <k-button-group v-if="showButtons" class="k-fabby-rag-buttons">
                  <k-button
                    v-if="!current.complete && !running"
                    icon="play"
                    theme="positive"
                    variant="filled"
                    @click="primaryAction"
                  >
                    {{ primaryLabel }}
                  </k-button>
                  <k-button
                    v-if="running"
                    icon="pause"
                    variant="filled"
                    @click="pause"
                  >
                    Nach diesem Schritt pausieren
                  </k-button>
                </k-button-group>
              </div>
            </div>
          </k-box>

          <div class="k-fabby-rag-maintenance">
            <h3>Erweiterte Wartung</h3>
            <p>
              Prüft alle Seiten auf Änderungen oder erstellt sämtliche Embeddings neu.
            </p>
            <k-button-group>
              <k-button
                icon="refresh"
                :disabled="running || isLoading"
                variant="filled"
                @click="prepareAndProcess(false)"
              >
                Wissensdatenbank prüfen und aktualisieren
              </k-button>
              <k-button
                icon="trash"
                theme="negative"
                :disabled="running || isLoading"
                @click="confirmForce"
              >
                Gesamte Wissensdatenbank zurücksetzen
              </k-button>
            </k-button-group>
          </div>
        </k-section>
      `
    }
  }
});
