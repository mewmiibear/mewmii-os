<?php
/**
 * Shared "+ Add Product" picker modal for Customer Order create/edit (see
 * assets/js/order-form.js). Included by modules/orders/create.php and edit.php after
 * they've prepared $pickerCategories, $pickerBrands, $pickerSuppliers. The actual
 * product/variation data comes from order_picker_products() embedded as JSON in the
 * page, not a separate AJAX call - same reasoning as the Supplier Order picker.
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
                    <div class="col-md-4">
                        <input type="text" class="form-control form-control-sm" id="picker-search" placeholder="Product name, SKU, or variation SKU">
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
                        <select class="form-select form-select-sm" id="picker-brand-filter">
                            <option value="">All brands</option>
                            <?php foreach ($pickerBrands as $brand): ?>
                                <option value="<?php echo (int) $brand['id']; ?>"><?php echo app_escape($brand['name']); ?></option>
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
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="picker-availability-filter">
                            <option value="">All availability</option>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="picker-supplier-filter">
                            <option value="">All suppliers</option>
                            <?php foreach ($pickerSuppliers as $supplier): ?>
                                <option value="<?php echo (int) $supplier['id']; ?>"><?php echo app_escape($supplier['name']); ?></option>
                            <?php endforeach; ?>
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

<div class="modal fade" id="newCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small">Name</label>
                    <input type="text" class="form-control form-control-sm" id="new-customer-name">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Phone</label>
                    <input type="text" class="form-control form-control-sm" id="new-customer-phone">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Email</label>
                    <input type="email" class="form-control form-control-sm" id="new-customer-email">
                </div>
                <div class="mb-0">
                    <label class="form-label small">Address</label>
                    <textarea class="form-control form-control-sm" id="new-customer-address" rows="2"></textarea>
                </div>
                <div class="text-danger small mt-2 d-none" id="new-customer-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="new-customer-save-btn">Save Customer</button>
            </div>
        </div>
    </div>
</div>
