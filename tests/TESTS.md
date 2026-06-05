# Test Specification

This document outlines the test specification for the Order Lifecycle plugin.

---

## Feature Tests

### [EventType](tests/Feature/EventTypeTest.php)

![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType string values` → it has the correct backing value for key cases.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises cart events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises line item events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises coupon events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises address events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises customer events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises shipping events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises order status events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises payment events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises email events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType::getCategory()` → it categorises checkout events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType boolean helpers` → it isCartEvent() returns true only for cart events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType boolean helpers` → it isPaymentEvent() returns true only for payment events.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `EventType boolean helpers` → it isEmailEvent() returns true only for email events.  

## Unit Tests

### [Formatter](tests/Unit/FormatterTest.php)

![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::quantityChange()` → it describes an increase.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::quantityChange()` → it describes a decrease.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::couponChange()` → it returns null when code is unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::couponChange()` → it describes an applied coupon.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::couponChange()` → it describes a removed coupon.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::statusChange()` → it returns null when status is unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::statusChange()` → it returns null when new status is null.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::statusChange()` → it describes a status change.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::shippingMethodChange()` → it returns null when method is unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::shippingMethodChange()` → it describes switching from one method to another.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::shippingMethodChange()` → it describes a newly set method.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::shippingMethodChange()` → it describes a removed method.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::countryChange()` → it returns null when country is unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::countryChange()` → it returns null when new country is null.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::countryChange()` → it describes a shipping country change by default.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::countryChange()` → it describes a billing country change.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::customerChange()` → it returns null when email is unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::customerChange()` → it normalizes empty strings to null.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::customerChange()` → it describes a new guest email.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::customerChange()` → it describes a new registered customer email.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::customerChange()` → it describes a removed email.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it detects an added item.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it detects a removed item.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it detects a quantity increase.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it detects a quantity decrease.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it returns an empty array when nothing changed.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::compareLineItems()` → it handles multiple simultaneous changes.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::sanitize()` → it escapes HTML special characters.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::sanitize()` → it passes safe text through unchanged.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) `Formatter::sanitize()` → it escapes single quotes.  
