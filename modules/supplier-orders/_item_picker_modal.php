<?php
/**
 * Shared "+ Add Product" picker modal for Supplier Order create/edit (see
 * assets/js/supplier-order-form.js). Included by modules/supplier-orders/create.php and
 * edit.php after they've prepared $pickerSuppliers and $pickerCategories. The actual
 * product/variation data comes from supplier_order_picker_products() embedded as JSON in
 * the page (see the calling page's <script id="supplier-order-form-data">), not a
 * separate AJAX call - fine at this admin-tool's product-count scale.
 */
?>
<div class="modal fade" id="productPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <input type="text" class="form-control form-control-sm" id="picker-search" placeholder="Product name, SKU, or variation SKU">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="picker-supplier-filter">
                            <option value="">All suppliers</option>
                            <?php foreach ($pickerSuppliers as $supplier): ?>
                                <option value="<?php echo (int) $supplier['id']; ?>"><?php echo app_escape($supplier['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="picker-category-filter">
                            <option value="">All categories</option>
                            <?php foreach ($pickerCategories as $category): ?>
                                <option value="<?php echo (int) $category['id']; ?>"><?php echo str_repeat('&nbsp;&nbsp;', $category['depth']) . app_escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="picker-type-filter">
                            <option value="">All types</option>
                            <option value="ready_stock">Ready Stock</option>
                            <option value="preorder">Preorder</option>
                            <option value="early_bird">Early Bird</option>
                        </select>
                    </div>
                </div>
                <div id="picker-results" style="max-height: 420px; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="picker-add-selected-btn">Add Selected</button>
            </div>
        </div>
    </div>
</div>
