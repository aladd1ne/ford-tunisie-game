# Bab El Khoukha Events Website

Welcome to the official repository of **Bab El Khoukha Events**, where history meets culture, and elegance meets entertainment. This website serves as a platform for exploring and booking unique events that celebrate Tunisia's rich 3,000-year heritage in breathtaking historical and natural locations.

## About Bab El Khoukha Events
Bab El Khoukha Events is dedicated to promoting Tunisia's vibrant cultural history and natural beauty. Through music, art, cinema, and culture, we aim to create unforgettable experiences that bridge the past and the future. Our mission is to organize elegant, entertaining events in Tunisia's most iconic settings, from the northernmost point of Africa to the heart of the Tunisian desert.

## Website Features
- **Event Listings:** Discover upcoming events with detailed descriptions and visuals.
- **Online Booking:** Seamlessly book tickets for our events.
- **Cultural Insights:** Learn about Tunisia's historical landmarks and the stories behind each event location.
- **Responsive Design:** Optimized for viewing on desktops, tablets, and mobile devices.

## Tech Stack
The website is built using modern web development technologies to ensure performance, scalability, and user satisfaction:
- **Backend:** PHP 8.2, Symfony 6.4
- **Frontend:** HTML5, CSS3, JavaScript (Vanilla JS)
- **Database:** MySQL
- **Additional Tools:** Snappy PDF for generating event programs and tickets

## Installation
Follow these steps to set up the project locally:

1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/bab-el-khoukha-events.git
   ```

2. Navigate to the project directory:
   ```bash
   cd bab-el-khoukha-events
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Set up environment variables by copying `.env.example` to `.env` and updating the values as needed:
   ```bash
   cp .env.example .env
   ```

5. Run database migrations:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

6. Start the development server:
   ```bash
   symfony serve
   ```

7. Access the website locally:
   [http://localhost:3200/]

## Contributing
We welcome contributions to enhance the Bab El Khoukha Events website. If you'd like to contribute:

1. Fork the repository.
2. Create a new branch for your feature:
   ```bash
   git checkout -b feature-name
   ```
3. Commit your changes and push the branch:
   ```bash
   git commit -m "Description of your feature"
   git push origin feature-name
   ```
4. Open a pull request.

## License
This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Contact
For inquiries, suggestions, or support, please contact us:
- Email: contact@babelkhoukha.tn
- Website: [Bab El Khoukha Events](https://babelkhoukha.tn)

---

Thank you for supporting Bab El Khoukha Events and helping us celebrate Tunisia's extraordinary heritage!
