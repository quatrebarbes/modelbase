import cytoscape from 'cytoscape';

/**
 * Module 3 (EX-306 à EX-311) : diagramme des relations Eloquent déclarées
 * par le modèle courant, layout `concentric` (modèle courant au centre,
 * poids 2 ; modèles liés sur un anneau extérieur unique, poids 1,
 * `levelWidth: 1` pour forcer un seul anneau quelle que soit la direction
 * réelle des relations) — port direct de l'ancien `RelationDiagram.vue`
 * (Phase 9bis), seul point du front nécessitant encore un bundle JS
 * (cytoscape n'est pas raisonnablement inlinable en vanilla JS).
 *
 * Couleurs lues depuis les custom properties CSS de la page au moment du
 * rendu (pas figées ici) pour rester cohérentes avec le thème clair/sombre
 * automatique (EX-108) sans dupliquer la palette.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('modelbaseRelationDiagram', (config) => ({
        cy: null,
        renderError: false,

        init() {
            this.$nextTick(() => this.render());
        },

        // EX-308 : un même modèle lié n'apparaît qu'une seule fois même s'il
        // est référencé par plusieurs relations (ex. Category -> Product via
        // `products` ET `latestProduct`) — sans ce Set, chaque relation
        // créerait son propre nœud dupliqué au lieu de converger vers un
        // nœud unique relié par plusieurs arêtes.
        buildElements() {
            const nodes = [
                {data: {id: config.model, label: config.model, center: true, unavailable: false, clickable: false}},
            ];
            const declared = new Set([config.model]);
            const edges = [];

            config.relations.forEach((relation, index) => {
                if (!declared.has(relation.related_model)) {
                    nodes.push({
                        data: {
                            id: relation.related_model,
                            label: relation.navigable
                                ? relation.related_model
                                : `${relation.related_model} (${config.unavailableLabel})`,
                            center: false,
                            unavailable: !relation.navigable,
                            clickable: relation.navigable,
                            url: relation.url,
                        },
                    });
                    declared.add(relation.related_model);
                }

                edges.push({
                    data: {
                        id: `edge-${index}`,
                        source: config.model,
                        target: relation.related_model,
                        label: relation.name,
                        sourceArity: '1',
                        targetArity: relation.multiplicity === 'many' ? '*' : '1',
                    },
                });
            });

            return [...nodes, ...edges];
        },

        render() {
            if (this.cy) {
                this.cy.resize();
                this.cy.fit(undefined, 40);

                return;
            }

            const style = getComputedStyle(document.documentElement);
            const color = (name) => style.getPropertyValue(name).trim();

            try {
                this.cy = cytoscape({
                    container: this.$refs.canvas,
                    elements: this.buildElements(),
                    userZoomingEnabled: true,
                    userPanningEnabled: true,
                    boxSelectionEnabled: false,
                    autoungrabify: true,
                    style: [
                        {
                            selector: 'node',
                            style: {
                                label: 'data(label)',
                                'text-wrap': 'wrap',
                                'text-max-width': '90px',
                                'text-valign': 'center',
                                'text-halign': 'center',
                                'background-color': color('--color-bg-muted'),
                                'border-color': color('--color-border'),
                                'border-width': 1,
                                color: color('--color-text'),
                                'font-size': 12,
                                shape: 'round-rectangle',
                                padding: '12px',
                                width: 'label',
                                height: 'label',
                            },
                        },
                        {
                            // EX-310 : le modèle courant se distingue visuellement des modèles liés.
                            selector: 'node[?center]',
                            style: {
                                'background-color': color('--color-primary'),
                                color: color('--color-bg'),
                                'border-width': 0,
                                'font-weight': 'bold',
                            },
                        },
                        {
                            selector: 'node[?unavailable]',
                            style: {
                                'border-style': 'dashed',
                                'text-opacity': 0.7,
                            },
                        },
                        {
                            selector: 'node.hover',
                            style: {
                                'border-color': color('--color-hover'),
                                'border-width': 2,
                            },
                        },
                        {
                            selector: 'edge',
                            style: {
                                width: 2,
                                'line-color': color('--color-border'),
                                'line-opacity': 0.4,
                                'target-arrow-shape': 'none',
                                'source-arrow-shape': 'none',
                                'curve-style': 'bezier',
                                'control-point-step-size': 90,
                                label: 'data(label)',
                                'font-size': 10,
                                color: color('--color-text'),
                                'text-background-opacity': 0,
                                'source-label': 'data(sourceArity)',
                                'target-label': 'data(targetArity)',
                                'source-text-offset': 8,
                                'target-text-offset': 8,
                            },
                        },
                    ],
                    layout: {
                        name: 'concentric',
                        concentric: (node) => (node.data('center') ? 2 : 1),
                        levelWidth: () => 1,
                        equidistant: true,
                        minNodeSpacing: 80,
                    },
                });

                this.cy.fit(undefined, 40);

                this.cy.on('mouseover', 'node[?clickable]', (event) => {
                    this.$refs.canvas.style.cursor = 'pointer';
                    event.target.addClass('hover');
                });
                this.cy.on('mouseout', 'node[?clickable]', (event) => {
                    this.$refs.canvas.style.cursor = '';
                    event.target.removeClass('hover');
                });
                // Navigation en page complète (pas Livewire.navigate) :
                // évite que le cache client de wire:navigate serve un
                // listing d'items obsolète en revenant sur cette page.
                this.cy.on('tap', 'node[?clickable]', (event) => {
                    window.location.href = event.target.data('url');
                });
            } catch (e) {
                this.renderError = true;
            }
        },
    }));
});
