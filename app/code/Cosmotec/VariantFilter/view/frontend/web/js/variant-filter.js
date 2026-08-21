define([
    'Cosmotec_VariantFilter/js/variant-row-toggle'
], function (bindRowToggle) {
    'use strict';
    return function (c, el) {
        const isSimple = el.dataset.isSimple;
        const parentProductUrl = el.dataset.parentProductUrl
        const groups = JSON.parse(el.dataset.groups);
        const products = JSON.parse(el.dataset.products);
        const groupsExcluded = JSON.parse(el.dataset.groupsExcluded);
        const attributesExcluded = JSON.parse(el.dataset.attributesExcluded);
        let state = {};
        

        function render() {
            let counter = 0;
            let html = '<div id="block-filters-container">';
            html += `<div id="cosmotec-filters">`;
            html += `<dl class="cosmotec-filters-heading">
                        <h2 >Filter by model</h2>
                    </dl>`;
            let groupClass= '';
            let showProductReturnButton = (isSimple === 'yes') ? ' display:block ' : '';
            
            Object.keys(groups)
            .filter(g => !groupsExcluded.includes(g))
            .forEach(g => {
                groupClass = g.replace(/\s/g,  '-');


                html += `    
                <div class="groups ${groupClass}"><div class="group-title">${g}</div>`;
                Object.entries(groups[g])
                .filter(([key, attrObj]) => !attributesExcluded.includes(key))
                .forEach( ([key, attrObj]) => {
                    ///const vals = [...new Set(products.map(p => p[a]).filter(Boolean))];
                    const vals = attrObj.values;
                    html += `<div class="filter-group group-${attrObj.code}">
                                <div class="filter-title">
                                    <input class="filter-group-checkbox" id="${attrObj.code}" type="checkbox" value="${attrObj.code}">
                                    <label for="${attrObj.code}" ><span>${attrObj.label}</span></label>
                                    <span class="filter-arrow-class"></span>
                                </div>
                                <ul class="filter-options">
                    `;
                    vals.forEach(v => {
                        counter++;
                        html += `
                            <li>
                                <input id="${attrObj.code}${counter}" type="checkbox" data-attr="${attrObj.code}" value="${v}">
                                <label for="${attrObj.code}${counter}" >
                                    <span>${v}</span>
                                </label>
                            </li>
                        `;
                    });
                    html += `</ul>`;
                    html += `</div>`; // filter group
                });
                html += `</div>`;  // groups
                
            });
            html += `<div class="area-loader-back-button"  style="${showProductReturnButton}">
                        <button type="button" class="product-return-list-btn" style="${showProductReturnButton}">
                            <b  class="icon iconfont iconfont-angle-left-small"></b>Back to Model List
                        </button>
                     </div>`; // button Back to Model List

            html += `</div>`; //cosmotec-filters

            html += `
                    <div class="variant-records-container">
                        <div class="variant-records-heading">
                            <h2 class="heading">
                                <span>We have </span> <span class="variant-list-counter"> - </span> <span> type candidate</span>
                            </h2>
                            <p class="ec-heading__notes">※ Please click on the product code or model for more information.</p>
                        </div>
                        <div class="variant-records">
                            
                            <table class="variant-table">
                                <thead>
                                    <tr class="table-heading-row">
                                    <th class="sticky-model">front.product.product_code<br />Model</th>
                                    <th>NWKF</th>
                                    <th>ICF</th>
                                    <th>VF</th>
                                    <th>VG</th>
                                    <th>A</th>
                                    <th>B</th>
                                    <th>C</th>
                                    <th>D</th>
                                    <th>P.C.D</th>
                                    <th>The number of electrode</th>
                                    <th class="sticky-stock">Stock & Delivery</th>
                                    <th class="sticky-price">Product price</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
            `;
            html += `</div>`; // block-filters-container

            el.innerHTML = html;

            ///////////////////////////////////////


           
            el.querySelector('.product-return-list-btn').addEventListener('click', e => {
                if (isSimple === 'yes') {
                    // redirect to parent product
                    if (parentProductUrl) {
                        window.location.replace(parentProductUrl);
                    }
                } else if (el.querySelector('.product-return-list-btn').style.display == 'block') {
                    el.querySelector('.area-loader-back-button').style.display = 'none';
                    el.querySelector('.product-return-list-btn').style.display = 'none';
                    el.querySelectorAll('tr').forEach(row => {
                        if (!row.classList.contains('table-heading-row')) {
                            row.classList.remove('hidden-row', 'open', 'current');
                            
                        }
                    });
                }
            });
            
           

            // Accordion toggle for filter groups
            el.querySelectorAll('.filter-group').forEach(group => {
                const title = group.querySelector('.filter-title');
                const checkbox = title.querySelector('input[type="checkbox"]');

                // Default state (open)
                group.classList.add('open');

                title.addEventListener('click', e => {
                    // Prevent toggle when clicking checkbox or label
                    if (
                        e.target.tagName === 'INPUT' ||
                        e.target.tagName === 'LABEL' ||
                        e.target.closest('label')
                    ) {
                        return;
                    }

                    group.classList.toggle('open');
                });
            });


            // Parent (filter title) checkbox handler
            el.querySelectorAll('.filter-group-checkbox').forEach(parentCb => {
                parentCb.addEventListener('change', () => {
                    const attr = parentCb.value; // e.g. ct_nwkf
                    const childCheckboxes = el.querySelectorAll(
                        `input[data-attr="${attr}"]`
                    );

                    // Check / uncheck all children
                    childCheckboxes.forEach(cb => {
                        cb.checked = parentCb.checked;
                    });

                    // Update state
                    if (parentCb.checked) {
                        state[attr] = [...childCheckboxes].map(cb => cb.value);
                    } else {
                        delete state[attr];
                    }

                    update();
                });
            });

            


            //////////////////////////

            // Filter option checkbox change handler
            el.querySelectorAll('.filter-options input[type="checkbox"]').forEach(cb => {
                cb.addEventListener('change', () => {
                    const attr = cb.dataset.attr;
                    state[attr] = [...el.querySelectorAll(
                        `input[data-attr="${attr}"]:checked`
                    )].map(i => i.value);
                    update();
                });
            });

            update();
            
        }

        function update() {
            
            // ---------- STEP 1: FILTER PRODUCTS ----------
            const filteredProducts = products.filter(p => {
                return Object.keys(state).every(attrCode => {
                    const selectedValues = state[attrCode];
                    if (!selectedValues || !selectedValues.length) return true;

                    const productValue = p[attrCode];
                    if (!productValue) return false;

                    // support multi-value attributes
                    const productValues = productValue.toString().split(',').map(v => v.trim());

                    // OR logic within attribute
                    return selectedValues.some(val => productValues.includes(val));
                });
            });


            // ---------- STEP 2: BUILD AVAILABLE FILTER VALUES ----------
            const availableValues = {};

            filteredProducts.forEach(p => {
                Object.keys(p).forEach(key => {
                    if (!availableValues[key]) {
                        availableValues[key] = new Set();
                    }

                    if (p[key]) {
                        p[key].toString().split(',').forEach(v => {
                            availableValues[key].add(v.trim());
                        });
                    }
                });
            });


            // ---------- STEP 3: UPDATE FILTER UI ----------
            el.querySelectorAll('.filter-group').forEach(group => {
                const attrCode = group.className.match(/group-([^\s]+)/)?.[1];
                if (!attrCode) return;

                let hasVisibleOption = false;

                group.querySelectorAll('.filter-options li').forEach(li => {
                    const input = li.querySelector('input');
                    const val = input.value;

                    if (availableValues[attrCode]?.has(val)) {
                        li.style.display = '';
                        hasVisibleOption = true;
                    } else {
                        input.checked = false;
                        li.style.display = 'none';
                    }
                });

                // hide entire attribute if no options left
                group.style.display = hasVisibleOption ? '' : 'none';
            });


            // ---------- STEP 4: RENDER TABLE ----------
            let openClass = (isSimple === 'yes') ? ' open ' : '';
            let currentClass = (isSimple === 'yes') ? ' current ' : '';
            let rows = '';
            filteredProducts.forEach(p => {
                rows += `
                <tr class="${currentClass}">
                    <td class="sticky-model">
                        <a class="product-info-link" href="${p.product_url}" data-row-contact="${p.id}">
                            ${p.sku}<br />${p.ct_model}
                        </a>
                    </td>
                    <td>${p.ct_nwkf}</td>
                    <td>${p.ct_icf}</td>
                    <td>${p.ct_vf}</td>
                    <td>${p.ct_vg}</td>
                    <td>${p.ct_a}</td>
                    <td>${p.ct_b}</td>
                    <td>${p.ct_c}</td>
                    <td>${p.ct_d}</td>
                    <td>${p.ct_pcd}</td>
                    <td>${p.ct_the_number_of_electrode}</td>
                    <td class="sticky-stock">
                        
                        <div class="sticky-stock-info">
                                        <div class="stock-description">
                                            <div class="stock-status-label ">stock</div>
                                            <span class="stock-description-quantity">${p.stock}</span>
                                            <span class="stock-description-date">1-2 Business Days</span>
                                        </div>
                                        <div class="waiting-stock-description">
                                            <div class="waiting-status-label">waiting for completion</div>
                                            <span class="waiting-quantity">3</span>
                                            <span class="waiting-date">Arrival on Jan 15,2026</span>
                                        </div>
                                        <div class="when-outofstock-description">
                                            <div class="when-outofstock-label">When out of stock</div>
                                            <span class="when-outofstock-quantity"> </span>
                                            <span class="when-outofstock-date">${p.ct_when_out_of_stock}</span>
                                        </div>
                                    </div>
                    </td>
                    <td class="sticky-price">${p.price}</td>
                </tr>
                <tr class="product-content-row-${p.id} product-content-row ${openClass}">
                    <td colspan="13">
                        <div class="variant-product-contents">
                            <div class="variant-product-info">
                                
                                
                                <div class="productModelDescription ">
                                    <div id="js-stock-status"></div>
                                    <div class="product-status">1-2 Business Days</div>
                                    
                                    <div class="product-name-dp ">
                                        <a id="js-short-name-with-locale" href="${p.product_url}">${p.name}</a>
                                    </div>
                                    <div class="product_Model_code ">Product code :${p.sku}   Model:${p.ct_model}</div>
                                </div>

                                <div class="product-cart">
                                        <div class="product-cart-price">${p.price} (with tax ${p.price})</div>
                                        <div  class="product-btnGroup">
                                            <a id="" class="contactBtn" href="https://cosmotec.us/pages/contact-us" target="_blank">
                                                <b aria-hidden="true" class="contact-icon"></b>
                                                Contact Us
                                            </a>
                                            <a id="" class="download-icon" href="https://cdn.shopify.com/s/files/1/2110/7727/files/N25BRS1.dxf">
                                                <b aria-hidden="true" class="icon iconfont iconfont-download"></b>
                                                2D CAD
                                            </a>
                                            <a id="" class="download-icon" href="https://cdn.shopify.com/s/files/1/2110/7727/files/N25BRS1.dxf">
                                                <b aria-hidden="true" class="icon iconfont iconfont-download"></b>
                                                3D CAD
                                            </a>
                                        </div>

                                        <div class="addcartItems">
                                            <div class="pdtQty">
                                                <label for="ec-productModel__quantityNum">Quantity</label>
                                                <input type="text"  class="ec-productModel__quantityNum js-addCartVal-quantity" name="shoppingCart_quantity" value="1" tabindex="1"> 
                                            </div>
                                            <button id="js-to-cart" class="addcartBtn" data-cart-product="4">
                                                <b aria-hidden="true" class="icon iconfont iconfont-shopping-cart"></b>
                                                <span class="ec-product__addCartText">Add to cart</span>
                                            </button>
                                        </div>
                                </div>

                            </div>
                            <div class="variant-product-img">
                                <img   src="https://static.cosmotec-co.jp/html/upload/save_image/NW寸法図1-4_d-67d39717c1ed4.jpg" >
                            </div>
                        </div>
                    </td>
                </tr>`;
            });

            el.querySelector('tbody').innerHTML = rows;
            el.querySelector('.variant-records-heading .variant-list-counter').innerHTML = filteredProducts.length;
            document.querySelector('#tab-label-cosmotec\\.variant\\.filter-title').innerHTML = 'Model List <br />( ' + filteredProducts.length + ' Matter )';

            bindRowToggle(el);
        }

        render();
    };
});
