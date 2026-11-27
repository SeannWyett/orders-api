# Orders API for FreshCart

## Requirements
PHP 8.2+
Composer
MySQL 8

## Setup

1. Run this on your Terminal: Composer Install, cp .env.example .env, php artisan key:generate
2. Configure the env file 
    * insert the key generated to APP_KEY
    * setup database credentials
3. Run Database Migrations
* In your terminal
    * php artisan migrate --seed
4. Run the app
    * php artisan serve
5. In another Terminal run : php artisan queue:work

### Accounts used for testing

| email | Password | Role |
| --- | --- | --- |
| staff1@gmail.com | password | Staff |
| staff2@gmail.com | password | Staff |
| customer1@gmail.com | password | Customer |
| customer2@gmail.com | password | Customer |
| customer3@gmail.com | password | Customer |
| seannwyett77@gmail.com | 12345678 | Customer |


## Tests

### Laravel Feature Tests
* Run in your terminal: php artisan test 

### Postman Collection
 * Located at storage/postman/api_collection.json

### Curl Commands

### Fetch all products
curl -s localhost:8000/api/v1/products

### Create a new order
curl -s -X POST localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"customer_name":"Ana","customer_email":"ted@example.com","items":[{"product_id":1,"quantity":2}]}'

### Filter existing orders
curl -s "localhost:8000/api/v1/orders?status=completed&customer_email=ted@example.com" -H "Accept: application/json"

### View a single order
curl -s localhost:8000/api/v1/orders/FC-20261125-0001 -H "Accept: application/json"

### Cancel an order
curl -s -X POST localhost:8000/api/v1/orders/FC-20261125-0001/cancel -H "Accept: application/json"

### register, copy the token
curl -s -X POST localhost:8000/api/v1/register -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Ana","email":"ted@example.com","password":"secret123","password_confirmation":"secret123"}'

### no token -> 401
curl -s localhost:8000/api/v1/orders -H "Accept: application/json"

### with token -> 200
curl -s localhost:8000/api/v1/orders -H "Accept: application/json" -H "Authorization: Bearer YOUR_TOKEN"

## Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/v1/products` | Retrieve a list of all users |
| POST | `/api/v1/register` | Create a new user |
| POST | `/api/v1/login` | Login as a user |
| POST | `/api/v1/orders` | Create a new Order, Accepts Idempotent-Key|
| POST | `/api/v1/logout` | Log out User |
| GET | `/api/v1/orders` | Retrieve Orders |
| GET | `/api/v1/orders{order}` | Retrieve a specific order |
| POST | `/api/v1/orders{order}/cancel` | Cancel an order |

## Assumptions Made

- Order Number Format (used Date with Year-Month-Day format and the order Id)
- Authentication
 * Staff role for low stock notificaton and order management and is seeded instead of creating one
 * Old Orders who have same email to a customer registeration gets claimed by that customer/account
 * Only Pending Orders can be Cancelled
 * Invoices are plain .txt files and are stored instorage/app/private/invoices
 * Guest or Unauthenticated users can place orders but cannot list, view or cancel them


## Bonus tasks accomplished
1. Authentication
2. Low-stock alert
3. Scheduled cleanup
4. Rate limiting