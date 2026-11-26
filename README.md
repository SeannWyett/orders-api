Curl Commands

# Fetch all products
curl -s localhost:8000/api/v1/products

# Create a new order
curl -s -X POST localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"customer_name":"Ana","customer_email":"ana@example.com","items":[{"product_id":1,"quantity":2}]}'

# Filter existing orders
curl -s "localhost:8000/api/v1/orders?status=completed&customer_email=ana@example.com" -H "Accept: application/json"

# View a single order
curl -s localhost:8000/api/v1/orders/FC-20261125-0001 -H "Accept: application/json"

# Cancel an order
curl -s -X POST localhost:8000/api/v1/orders/FC-20261125-0001/cancel -H "Accept: application/json"

# register, copy the token
curl -s -X POST localhost:8000/api/v1/register -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"name":"Ana","email":"ana@example.com","password":"secret123","password_confirmation":"secret123"}'

# no token -> 401
curl -s localhost:8000/api/v1/orders -H "Accept: application/json"

# with token -> 200
curl -s localhost:8000/api/v1/orders -H "Accept: application/json" -H "Authorization: Bearer YOUR_TOKEN"