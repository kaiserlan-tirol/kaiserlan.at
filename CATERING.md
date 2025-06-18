# CATERING API

Catering ist so konzipiert, dass das Terminal auch mal offline sein kann und seine Orders dann abliefert wenn es wieder online ist. Am besten macht es das aber direkt.




## Benutzer mit Addons

Liefert alle Benutzer mit einem Ticket (also aktuelle Lanbesucher) inklusive der gebuchten Addons. D.h. so kann man die Preise auf 0 setzen oder eben nicht, abhängig ob er Toastflat hat.

http://localhost:8002/api/catering/users-with-tickets

Example:
```json
[
  {
    "user":"b317b884-3b35-4024-9e7d-5bc8d7924e06",
    "nickname":"BoArDa",
    "firstname":"Markus" ,"surname":"Edenhauser",
    "addons":[
      {"id":1,"label":"Toastflat"}
    ]
  },
  {
    "user":"e8966652-8d15-4f6a-9002-97f603df10b9",
    "nickname":"Karateschnitte",
    "firstname":"Guido","surname":"Brandauer",
    "addons":[
      {"id":2,"label":"Tagesticket oder U18"},
      {"id":3,"label":"Erstbesuch"}
    ]
  }
]
```

# Create Order

Bezahlt status ist automatisch auf nicht bezahlt, außer man setzt `"paid": true,` oder wenn die Gesamtsumme 0€ ist (wenn er eine Flat hat). 


```bash
# id is guido / karateschnitte
curl -X POST http://localhost:8002/api/catering/order \
  -H "Content-Type: application/json" \
  -d '{
    "userId": "e8966652-8d15-4f6a-9002-97f603df10b9", 
    "paid": true,
    "order": [
      {
        "product": "SKT",
        "amount": 2
      }
    ]
  }' | jq .
```